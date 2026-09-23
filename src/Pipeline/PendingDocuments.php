<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\Crawl\LinkExtractor;
use App\Crawl\SiteConfig;

/**
 * Penemu BERKAS DOKUMEN yang sudah terunduh tetapi belum masuk Qdrant
 * (storage/documents/<site-id>/).
 *
 * Latar belakang: berkas dokumen mentah sengaja TIDAK dihapus setelah crawl
 * bila parse/embed-nya gagal, supaya bisa diproses ulang. Dua sebab paling
 * sering (keduanya nyata pada log 2026-09-20/21):
 *
 *   1. layanan /parse (worker-ingest) sedang MATI saat run berjalan sehingga
 *      SEMUA PDF dilewati ("tidak ada chunk yang dikirim ke /embed");
 *   2. run berhenti karena batas max_pages / max_requests_per_crawl sebelum
 *      PDF pada halaman detail (mis. /front/dokumen/detail/<id> yang memuat
 *      tautan "Download PDF") sempat diunduh.
 *
 * Nama berkas hasil unduhan menyimpan 8 digit pertama document_id (UUID v5
 * dari URL), jadi URL asli sebuah berkas bisa DIPULIHKAN dengan menghitung
 * UUID v5 semua URL yang pernah terlihat pada site itu:
 *
 *   storage/markdown/<site>/<slug>--<hash8>.md  -> header "- sumber: <url>"
 *                                                  + semua tautan di isinya
 *                                                  (mis. tautan "Download PDF")
 *   storage/html/<site>/<slug>--<hash8>.html    -> semua tautan <a>/<object>/
 *                                                  <embed>/<iframe>/<source>
 *
 * Tanpa pemulihan ini, dokumen yatim hanya bisa diproses dengan crawl ulang
 * (mahal: satu site bisa berjam-jam). Berkas yang URL-nya benar-benar tidak
 * ditemukan dilaporkan terpisah supaya pengguna tahu harus crawl ulang.
 */
final class PendingDocuments
{
    /** Batas byte yang dibaca per berkas saat mencari kandidat URL. */
    private const MAX_READ_BYTES = 2_000_000;

    /** Batas jumlah kandidat URL (jaring pengaman untuk site raksasa). */
    private const MAX_CANDIDATES = 50_000;

    /** Aset yang tidak pernah dipakai sebagai kandidat URL dokumen. */
    private const SKIP_EXTENSIONS = [
        'js', 'mjs', 'cjs', 'css', 'map',
        'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif', 'ico', 'bmp',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        'mp4', 'mp3', 'wav', 'ogg', 'avi', 'mov',
        'zip', 'rar', '7z', 'gz', 'tar', 'apk', 'exe',
    ];

    public function __construct(private VectorStore $store)
    {
    }

    /**
     * Jumlah berkas dokumen yang masih menunggu diproses (cepat, tanpa
     * memulihkan URL) -- dipakai ringkasan crawl supaya berkas yatim terlihat.
     */
    public function count(SiteConfig $site): int
    {
        return count($this->files($site));
    }

    /**
     * Cari berkas dokumen yatim beserta URL aslinya.
     *
     * @param list<string> $urlsTambahan URL dari opsi --url (paling dipercaya,
     *                                   dipakai bila berkasnya ada di storage)
     * @param int          $limit        batas jumlah berkas (0 = tanpa batas)
     *
     * @return array{
     *     items: list<array{url: string, path: string, berkas: string, sumber: string}>,
     *     tanpa_url: list<string>,
     *     dilewati_limit: list<string>,
     *     berkas: int,
     *     kandidat: int
     * }
     */
    public function scan(SiteConfig $site, array $urlsTambahan = [], int $limit = 0): array
    {
        $files = $this->files($site);
        $items = [];
        $tanpaUrl = [];
        $dilewati = [];

        if ($files === []) {
            return ['items' => [], 'tanpa_url' => [], 'dilewati_limit' => [], 'berkas' => 0, 'kandidat' => 0];
        }

        $menunjuk = [];
        foreach ($urlsTambahan as $url) {
            $this->register($menunjuk, (string) $url, 'url');
        }

        $this->collectFromMarkdown($site, $menunjuk);
        $this->collectFromHtml($site, $menunjuk);

        foreach ($files as $file) {
            $prefix = self::prefixDariNamaBerkas($file['nama']);
            $cocok = $prefix !== '' ? ($menunjuk[$prefix] ?? null) : null;

            if ($cocok === null) {
                $tanpaUrl[] = $file['path'];

                continue;
            }

            $items[] = [
                'url' => $cocok['url'],
                'path' => $file['path'],
                'berkas' => $file['nama'],
                'sumber' => $cocok['sumber'],
            ];
        }

        // Yang URL-nya sudah pasti (--url) didahulukan, lalu hasil pemulihan
        // dari markdown, terakhir dari HTML; di dalam kelompok yang sama
        // urutannya alfabetis supaya hasilnya dapat diulang (deterministik)
        // ketika ada batas --limit.
        usort($items, static function (array $a, array $b): int {
            $prioritas = ['url' => 0, 'markdown' => 1, 'html' => 2];
            $beda = ($prioritas[$a['sumber']] ?? 3) <=> ($prioritas[$b['sumber']] ?? 3);

            if ($beda !== 0) {
                return $beda;
            }

            return strcmp($a['berkas'], $b['berkas']);
        });

        if ($limit > 0 && count($items) > $limit) {
            foreach (array_slice($items, $limit) as $sisa) {
                $dilewati[] = $sisa['path'];
            }

            $items = array_slice($items, 0, $limit);
        }

        return [
            'items' => $items,
            'tanpa_url' => $tanpaUrl,
            'dilewati_limit' => $dilewati,
            'berkas' => count($files),
            'kandidat' => count($menunjuk),
        ];
    }

    /**
     * Daftar berkas dokumen mentah satu site (nama + path absolut).
     *
     * @return list<array{nama: string, path: string}>
     */
    private function files(SiteConfig $site): array
    {
        $dir = $this->store->documentsDir($site->id);

        if (!is_dir($dir)) {
            return [];
        }

        $hasil = [];

        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }

            $nama = basename($path);
            $extension = strtolower(pathinfo($nama, PATHINFO_EXTENSION));

            if (!in_array($extension, SiteConfig::DOCUMENT_EXTENSIONS, true)) {
                continue;
            }

            if ((int) @filesize($path) <= 0) {
                continue;
            }

            $hasil[] = ['nama' => $nama, 'path' => $path];
        }

        return $hasil;
    }

    /**
     * Kandidat URL dari berkas markdown site: header "- sumber:" dan semua
     * tautan di dalam isinya (tautan "Download PDF" pada halaman detail ikut
     * terbaca walaupun PDF-nya sendiri belum pernah diparse).
     *
     * @param array<string, array{url: string, sumber: string}> $menunjuk
     */
    private function collectFromMarkdown(SiteConfig $site, array &$menunjuk): void
    {
        $dir = $this->store->markdownDir($site->id);

        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.md') ?: [] as $path) {
            foreach (self::urlsPada($this->readHead($path)) as $url) {
                $this->register($menunjuk, $url, 'markdown');
            }

            if (count($menunjuk) >= self::MAX_CANDIDATES) {
                return;
            }
        }
    }

    /**
     * Kandidat URL dari HTML mentah site (tautan <a href>/<object data>/
     * <embed src>/<iframe src>/<source src>). URL halaman untuk menyelesaikan
     * tautan relatif diambil dari markdown dengan nama berkas yang sama
     * ("- sumber: <url>"): HTML dan markdown satu halaman memakai
     * <slug>--<hash8> yang identik. Bila markdown-nya tidak ada, hanya URL
     * absolut yang dipakai (tautan relatif tidak bisa dipertanggungjawabkan).
     *
     * @param array<string, array{url: string, sumber: string}> $menunjuk
     */
    private function collectFromHtml(SiteConfig $site, array &$menunjuk): void
    {
        $dir = $this->store->htmlDir($site->id);

        if (!is_dir($dir)) {
            return;
        }

        $markdownDir = $this->store->markdownDir($site->id);

        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.htm*') ?: [] as $path) {
            $isi = $this->readHead($path);

            if ($isi === '') {
                continue;
            }

            $nama = basename($path);
            $sumber = $markdownDir . DIRECTORY_SEPARATOR . (preg_replace('/\.html?$/i', '.md', $nama) ?? $nama);
            $halaman = is_file($sumber) ? self::sumberMarkdown($this->readHead($sumber)) : '';

            if ($halaman !== '') {
                foreach (LinkExtractor::extract($isi, $halaman, self::SKIP_EXTENSIONS) as $url) {
                    $this->register($menunjuk, $url, 'html');
                }
            }

            foreach (self::urlsPada($isi) as $url) {
                $this->register($menunjuk, $url, 'html');
            }

            if (count($menunjuk) >= self::MAX_CANDIDATES) {
                return;
            }
        }
    }

    /**
     * Daftarkan satu URL beserta variasi normalisasinya.
     *
     * @param array<string, array{url: string, sumber: string}> $menunjuk
     */
    private function register(array &$menunjuk, string $url, string $sumber): void
    {
        if (count($menunjuk) >= self::MAX_CANDIDATES) {
            return;
        }

        foreach (self::variants($url) as $kandidat) {
            $prefix = self::prefix($kandidat);

            if ($prefix !== '' && !isset($menunjuk[$prefix])) {
                $menunjuk[$prefix] = ['url' => $kandidat, 'sumber' => $sumber];
            }
        }
    }

    /**
     * Semua URL absolut pada teks markdown/HTML. Tanda penutup markdown
     * ("[Download PDF](https://…pdf)") sudah dikecualikan dari pola sehingga
     * URL-nya tidak ikut membawa tanda kurung.
     *
     * @return list<string>
     */
    private static function urlsPada(string $teks): array
    {
        if ($teks === '') {
            return [];
        }

        $jumlah = preg_match_all('~https?://[^\s<>"\'()\[\]{}]+~i', $teks, $cocok);

        if ($jumlah === false || $jumlah === 0) {
            return [];
        }

        $urls = [];

        foreach ($cocok[0] as $url) {
            $url = rtrim(trim((string) $url), '.,;:');

            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * URL asli sebuah halaman dari header markdown "- sumber: <url>".
     */
    private static function sumberMarkdown(string $isi): string
    {
        return preg_match('/^-sumber:\s*(\S+)\s*$/mi', $isi, $cocok) === 1 ? trim($cocok[1]) : '';
    }

    /**
     * Baca bagian awal berkas saja (cukup untuk mencari URL) supaya markdown
     * raksasa tidak dimuat seluruhnya ke memori.
     */
    private function readHead(string $path): string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        $isi = (string) @fread($handle, self::MAX_READ_BYTES);
        @fclose($handle);

        return $isi;
    }

    /**
     * 8 digit pertama document_id dari nama berkas (<slug>--<hash8>.<ext>);
     * lihat VectorStore::documentPath().
     */
    private static function prefixDariNamaBerkas(string $nama): string
    {
        return preg_match('/--([0-9a-f]{8})\.[A-Za-z0-9]{1,6}$/', $nama, $cocok) === 1
            ? strtolower($cocok[1])
            : '';
    }

    /**
     * 8 digit pertama UUID v5 URL (identitas dokumen pada payload vektor).
     */
    private static function prefix(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        try {
            return substr(str_replace('-', '', MarkdownChunker::documentIdForUrl($url)), 0, 8);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Variasi URL: apa adanya + bentuk ternormalisasi yang dipakai crawler
     * (LinkExtractor menormalkan skema/host/path/kueri), karena document_id
     * dihitung dari URL yang sudah dinormalkan itu.
     *
     * @return list<string>
     */
    private static function variants(string $url): array
    {
        $url = trim($url);
        $hasil = [$url];

        if ($url !== '') {
            $html = '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '"></a>';

            foreach (LinkExtractor::extract($html, $url) as $normal) {
                $hasil[] = $normal;
            }
        }

        return array_values(array_unique(array_filter($hasil, static fn (string $u): bool => $u !== '')));
    }
}
