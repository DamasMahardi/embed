<?php

declare(strict_types=1);

namespace App\Crawl;

use App\Http\HttpClient;
use App\Http\HttpResponse;
use App\Support\Text;

/**
 * Penemu BERKAS DOKUMEN (PDF/DOCX/XLSX/...) secara OTOMATIS dari log jaringan
 * browser (Chrome NetLog) -- tanpa aturan per situs.
 *
 * Latar belakang: sebagian situs menyembunyikan tautan unduhan di balik
 * JavaScript. Halaman /informasi-publik di www.kemendagri.go.id misalnya hanya
 * memuat 9 judul bab di HTML/DOM; daftar PDF-nya baru diminta browser lewat
 * https://backend.kemendagri.go.id/api/v1/informasi-publik/public sehingga
 * crawler tidak pernah menemukan tautannya (lihat kasus RUN-20260918-224106).
 * Setiap halaman yang dirender browser sudah MEMANGGIL endpoint itu sendiri;
 * NetLog merekam semua permintaan tersebut, jadi crawler cukup membacanya:
 *
 *   1. URL berkas dokumen yang diminta halaman -> langsung diantrekan;
 *   2. endpoint JSON yang dipanggil halaman -> dipanggil ulang oleh crawler
 *      (GET saja) lalu isinya ditelusuri: setiap teks yang berakhir ekstensi
 *      dokumen atau menunjuk endpoint unduhan dikumpulkan, path relatif
 *      ("/files/a.pdf") diselesaikan ke basis yang benar dengan probe HEAD
 *      (mis. <host API>/uploads) sehingga tidak menghasilkan ratusan URL 404;
 *   3. judul diambil dari field tetangga yang lazim (item/title/judul/name/...)
 *      supaya payload vektor berisi judul, bukan nama berkas.
 *
 * Yang TIDAK dilakukan: memanggil endpoint POST, memanggil URL pelacakan
 * (analytics/visitor-track dsb.), dan menyentuh host di luar keluarga domain
 * halaman (kecuali berkas dokumen yang memang diminta halaman itu sendiri).
 */
final class DocumentHarvest
{
    /** Batas ukuran respons JSON yang dibaca. */
    public const MAX_BYTES = 8_388_608;

    /** Ekstensi aset yang tidak pernah dipanggil ulang sebagai API. */
    private const SKIP_EXTENSIONS = [
        'js', 'mjs', 'cjs', 'css', 'map', 'json.gz',
        'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif', 'ico', 'bmp',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        'mp4', 'mp3', 'wav', 'ogg', 'avi', 'mov',
        'zip', 'rar', '7z', 'gz', 'tar', 'apk', 'exe',
    ];

    /**
     * Kata pada PATH yang menandakan endpoint bukan sumber daftar dokumen
     * (pelacakan/aksi), supaya crawler tidak memanggilnya.
     */
    private const SKIP_PATH_KEYWORDS = [
        'analytics', 'visitor', 'track', 'statistic', 'metrics', 'heartbeat', 'ping',
        'newsletter', 'subscribe', 'notification', 'report-to', 'csp-report',
        'login', 'logout', 'auth', 'token', 'session', 'captcha', 'csrf',
        'contact', 'survey', 'questionnaire', 'sentiment', 'comment', 'rating',
        'edit', 'delete', 'create', 'update', 'store', 'upload', 'import', 'export',
        'cart', 'checkout', 'payment', 'subscribe', 'unsubscribe',
    ];

    /** Nama field yang lazim dipakai sebagai judul item. */
    private const TITLE_KEYS = ['item', 'title', 'judul', 'name', 'nama', 'label', 'subject', 'desc', 'description', 'keterangan'];

    /**
     * Kunci yang isinya METADATA teknis, bukan judul: nilainya tidak dipakai
     * sebagai judul cadangan. Kasus nyata (API /siphc/api/forms Kemendagri):
     * {"type":"file","question":"9. Jika Ya, Unggah Dokumen Renstra ..."} --
     * tanpa daftar ini judul yang terbaca adalah "file", bukan pertanyaan yang
     * menjelaskan dokumennya.
     */
    private const SKIP_TITLE_KEYS = [
        'type', 'kind', 'jenis', 'mode', 'status', 'state', 'format', 'ext', 'extension',
        'mime', 'mime_type', 'content_type', 'id', 'uuid', 'guid', 'token', 'slug', 'hash',
        'checksum', 'size', 'ukuran', 'bytes', 'order', 'sort', 'sortir', 'index', 'indeks',
        'no', 'nomor', 'number', 'page', 'halaman', 'step', 'level', 'depth', 'parent',
        'required', 'wajib', 'loading', 'selected', 'active', 'enabled', 'verified',
        'path', 'url', 'uri', 'link', 'href', 'file', 'files', 'filename', 'file_name', 'nama_file',
    ];

    /**
     * Nilai yang jelas bukan judul: jenis berkas, label tombol, dan nilai
     * boolean. Nilai seperti ini sering menjadi teks pertama pada JSON daftar
     * (mis. {"type":"file"}) sehingga menutupi judul yang sebenarnya.
     */
    private const NON_TITLE_VALUES = [
        'file', 'files', 'document', 'documents', 'dokumen', 'berkas', 'lampiran',
        'attachment', 'attachments', 'download', 'downloads', 'unduh', 'unggah',
        'upload', 'uploads', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'csv', 'zip', 'rar', 'image', 'images', 'gambar', 'foto', 'video', 'audio',
        'true', 'false', 'null', 'none', 'ya', 'tidak', 'yes', 'no',
        'untitled', 'tanpa judul', 'no title',
    ];

    public function __construct(
        private readonly HttpClient $http,
    ) {
    }

    /**
     * Temukan berkas dokumen dari log jaringan hasil render satu halaman.
     *
     * @param int $maxDocuments  batas jumlah berkas yang dikembalikan
     * @param int $maxApis       batas jumlah endpoint API yang dipanggil ulang
     *
     * @return array{
     *     berkas: array<string, string>,
     *     laporan: list<array{endpoint: string, http: int, berkas: int, galat: string}>
     * } "berkas" = url => judul (judul boleh "")
     */
    public function harvest(SiteConfig $site, string $pageUrl, string $netLogPath, int $maxDocuments = 200, int $maxApis = 8): array
    {
        $berkas = [];
        $laporan = [];
        $batas = max(1, $maxDocuments);

        $kandidat = self::candidateUrls($netLogPath, $pageUrl);

        // 1. Berkas dokumen yang MEMANG diminta halaman itu (mis. pratinjau PDF).
        foreach ($kandidat['berkas'] as $url) {
            if (count($berkas) >= $batas) {
                break;
            }

            $berkas[$url] = self::titleFromUrl($url);
        }

        // 2. Endpoint JSON yang dipanggil halaman: dipanggil ulang crawler lalu
        //    isinya ditelusuri untuk mencari path/URL berkas dokumen.
        $dipakai = 0;

        foreach ($kandidat['api'] as $apiUrl) {
            if ($dipakai >= max(0, $maxApis) || count($berkas) >= $batas) {
                break;
            }

            $dipakai++;
            $hasil = $this->harvestJson($site, $apiUrl, $pageUrl, $batas - count($berkas));

            $laporan[] = [
                'endpoint' => $apiUrl,
                'http' => $hasil['http'],
                'berkas' => count($hasil['berkas']),
                'galat' => $hasil['galat'],
            ];

            foreach ($hasil['berkas'] as $url => $judul) {
                if (!isset($berkas[$url]) && count($berkas) < $batas) {
                    $berkas[$url] = $judul;
                }
            }
        }

        return ['berkas' => $berkas, 'laporan' => $laporan];
    }

    /**
     * URL yang layak ditindaklanjuti dari log jaringan satu halaman.
     *
     * @return array{berkas: list<string>, api: list<string>}
     */
    public static function candidateUrls(string $netLogPath, string $pageUrl): array
    {
        if (!is_file($netLogPath)) {
            return ['berkas' => [], 'api' => []];
        }

        $raw = (string) @file_get_contents($netLogPath);
        if ($raw === '') {
            return ['berkas' => [], 'api' => []];
        }

        $pageHost = Text::hostname($pageUrl);
        $pageKey = self::urlKey($pageUrl);
        $berkas = [];
        $api = [];

        // Cukup regex: NetLog adalah JSON besar, sedangkan URL selalu muncul
        // sebagai teks. Nilai yang tidak valid (mis. "... same_site") disaring
        // oleh FILTER_VALIDATE_URL.
        if (preg_match_all('#https?://[^\s"\'<>\\\\]+#', $raw, $cocok) === 0) {
            return ['berkas' => [], 'api' => []];
        }

        foreach ($cocok[0] as $url) {
            $url = html_entity_decode((string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (filter_var($url, FILTER_VALIDATE_URL) === false || self::urlKey($url) === $pageKey) {
                continue;
            }

            $host = Text::hostname($url);
            if ($host === '') {
                continue;
            }

            // Berkas dokumen: boleh host lain (mis. CDN), karena halaman itu
            // sendiri yang memintanya.
            if (SiteConfig::isDocumentUrl($url)) {
                $berkas[$url] = true;

                continue;
            }

            if (!self::sameSite($pageHost, $host)) {
                continue;
            }

            if (SiteConfig::looksLikeDocumentEndpoint($url)) {
                $berkas[$url] = true;

                continue;
            }

            $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            // Akar situs ("https://contoh.go.id" / ".../") bukan sumber daftar
            // dokumen: pernah tercatat di NetLog sebagai initiator saja.
            if (trim($path, '/') === '') {
                continue;
            }

            if ($ext !== '' && in_array($ext, self::SKIP_EXTENSIONS, true)) {
                continue;
            }

            if (self::skipEndpoint($path)) {
                continue;
            }

            $api[$url] = true;
        }

        $apiList = array_keys($api);

        // Endpoint "/api/" didahulukan: paling sering berisi daftar dokumen.
        usort($apiList, static function (string $a, string $b): int {
            $pa = strtolower((string) (parse_url($a, PHP_URL_PATH) ?? ''));
            $pb = strtolower((string) (parse_url($b, PHP_URL_PATH) ?? ''));

            return (str_contains($pa, '/api/') ? 0 : 1) <=> (str_contains($pb, '/api/') ? 0 : 1);
        });

        return ['berkas' => array_keys($berkas), 'api' => $apiList];
    }

    /**
     * Panggil ulang endpoint JSON halaman lalu kumpulkan berkas dokumen di
     * dalamnya (termasuk path relatif yang diselesaikan ke basis yang benar).
     *
     * @return array{berkas: array<string, string>, http: int, galat: string}
     */
    private function harvestJson(SiteConfig $site, string $apiUrl, string $pageUrl, int $maxDocuments): array
    {
        $response = $this->http->get($apiUrl, [
            'timeout' => max(5, min(60, $site->requestTimeout)),
            'max_bytes' => self::MAX_BYTES,
            'retry_addresses' => true,
            'headers' => [
                'Accept' => 'application/json, text/plain;q=0.9, */*;q=0.8',
                'Accept-Language' => 'id-ID,id;q=0.9,en;q=0.8',
            ],
        ]);

        if (!$response->ok()) {
            return ['berkas' => [], 'http' => $response->status, 'galat' => $response->errorMessage(160)];
        }

        $json = json_decode($response->body, true);
        if (!is_array($json)) {
            return ['berkas' => [], 'http' => $response->status, 'galat' => 'respons bukan JSON'];
        }

        $absolut = [];
        $relatif = [];
        self::collect($json, $absolut, $relatif, 0);

        $berkas = $absolut;
        $galat = '';

        if ($relatif !== [] && count($berkas) < max(1, $maxDocuments)) {
            // Satu API bisa mencampur gaya path (mis. "/files/..." dan
            // "files/..."): basis dicari per kelompok segmen pertama supaya
            // berkas dari gaya kedua tidak hilang.
            $grup = [];
            foreach ($relatif as $path => $judul) {
                $segmen = explode('/', ltrim($path, '/'))[0] ?? '';
                $grup[$segmen][$path] = $judul;
            }

            foreach ($grup as $paths) {
                $contoh = (string) array_key_first($paths);
                $base = $this->resolveBase($site, $contoh, $apiUrl, $pageUrl);

                if ($base === null) {
                    $galat = 'path relatif tidak bisa diselesaikan menjadi berkas dokumen (contoh: ' . $contoh . ')';

                    continue;
                }

                foreach ($paths as $path => $judul) {
                    if (count($berkas) >= max(1, $maxDocuments)) {
                        break 2;
                    }

                    $berkas[$base . $path] ??= $judul;
                }
            }
        }

        return ['berkas' => $berkas, 'http' => $response->status, 'galat' => $galat];
    }

    /**
     * Telusuri struktur JSON dan kumpulkan nilai teks yang menunjuk berkas
     * dokumen. URL absolut dicatat di $absolut, path relatif ("/files/a.pdf") di
     * $relatif (keduanya url/path => judul).
     *
     * @param array<string, string> $absolut
     * @param array<string, string> $relatif
     */
    private static function collect(mixed $node, array &$absolut, array &$relatif, int $depth): void
    {
        if (!is_array($node) || $depth > 8) {
            return;
        }

        $teks = [];
        foreach ($node as $kunci => $nilai) {
            if (is_string($nilai) && trim($nilai) !== '') {
                $teks[(string) $kunci] = trim($nilai);
            }
        }

        if ($teks !== []) {
            $judul = self::guessTitle($teks);

            foreach ($teks as $nilai) {
                if (preg_match('#^https?://#i', $nilai) === 1) {
                    if (self::isDocumentUrl($nilai)) {
                        $absolut[$nilai] ??= $judul;
                    }

                    continue;
                }

                if (str_starts_with($nilai, '/') && self::isDocumentUrl('http://contoh.test' . $nilai)) {
                    $relatif[$nilai] ??= $judul;

                    continue;
                }

                // Path relatif TANPA garis miring awal ("files/a.pdf") juga
                // muncul pada sebagian API (mis. informasi_publik_files/...).
                if (preg_match('/\s/', $nilai) === 1) {
                    continue;
                }

                $path = '/' . ltrim($nilai, '/');

                if (self::isDocumentUrl('http://contoh.test' . $path)) {
                    $relatif[$path] ??= $judul;
                }
            }
        }

        foreach ($node as $nilai) {
            if (is_array($nilai)) {
                self::collect($nilai, $absolut, $relatif, $depth + 1);
            }
        }
    }

    /**
     * URL ini menunjuk berkas dokumen (ekstensi dokumen atau endpoint unduhan)?
     */
    private static function isDocumentUrl(string $url): bool
    {
        return SiteConfig::isDocumentUrl($url) || SiteConfig::looksLikeDocumentEndpoint($url);
    }

    /**
     * Tebak judul item dari nilai teks di level yang sama: field yang lazim
     * dipakai lebih dahulu, lalu teks pertama yang bukan path/URL/angka.
     *
     * @param array<array-key, string> $teks
     */
    private static function guessTitle(array $teks): string
    {
        foreach (self::TITLE_KEYS as $kunci) {
            foreach ($teks as $nama => $nilai) {
                // Kunci array hasil json_decode() bisa berupa ANGKA: API sering
                // mengirim DAFTAR teks (mis. "files": ["a.pdf", "b.pdf"]) yang
                // menjadi $teks[0], $teks[1], ... sehingga strtolower() ditolak
                // TypeError karena hanya menerima string. Cast dulu agar objek
                // biasa (kunci nama field) maupun daftar teks sama-sama aman.
                if (strtolower((string) $nama) !== $kunci
                    || self::looksLikePath($nilai)
                    || self::isNonTitle($nilai)) {
                    continue;
                }

                return Text::oneLine($nilai, 240);
            }
        }

        foreach ($teks as $nama => $nilai) {
            // Nilai dari kunci teknis (type/status/url/...) dan nilai generik
            // ("file", "Ya"/"Tidak") bukan judul: tanpa penyaringan ini judul
            // berkas hasil temuan otomatis bisa berisi "file" (lihat
            // SKIP_TITLE_KEYS / NON_TITLE_VALUES).
            if (is_string($nama) && in_array(strtolower($nama), self::SKIP_TITLE_KEYS, true)) {
                continue;
            }

            if (self::looksLikePath($nilai) || self::isNonTitle($nilai) || mb_strlen($nilai) < 3) {
                continue;
            }

            return Text::oneLine($nilai, 240);
        }

        return '';
    }

    /**
     * Nilai ini bukan judul yang bisa dibaca (jenis berkas/label tombol/boolean)?
     */
    private static function isNonTitle(string $nilai): bool
    {
        return in_array(strtolower(trim($nilai)), self::NON_TITLE_VALUES, true);
    }

    /**
     * Apakah teks ini path/URL berkas (bukan judul yang bisa dibaca)?
     */
    private static function looksLikePath(string $nilai): bool
    {
        if (preg_match('#^https?://#i', $nilai) === 1 || str_starts_with($nilai, '/')) {
            return true;
        }

        return preg_match('/^[a-z0-9._%\/\-]+\.(pdf|docx?|xlsx?|pptx?|rtf)$/i', $nilai) === 1;
    }

    /**
     * Judul cadangan dari nama berkas di URL (mis. "laporan-apbd-2024.pdf").
     */
    private static function titleFromUrl(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $nama = rawurldecode((string) pathinfo($path, PATHINFO_FILENAME));

        return Text::oneLine(str_replace(['-', '_'], ' ', $nama), 240);
    }

    /**
     * Cari basis yang benar untuk path relatif hasil JSON: basis yang membuat
     * satu contoh path benar-benar mengembalikan berkas dokumen.
     *
     * Kandidat (berurutan): host API, host API + /uploads, host API + /storage,
     * host halaman, host halaman + /uploads, host halaman + /storage. Untuk
     * Kemendagri hasilnya: https://backend.kemendagri.go.id/uploads.
     */
    private function resolveBase(SiteConfig $site, string $contohPath, string $apiUrl, string $pageUrl): ?string
    {
        $kandidat = [];

        foreach ([self::origin($apiUrl), self::origin($pageUrl)] as $origin) {
            if ($origin === '') {
                continue;
            }

            foreach (['', '/uploads', '/storage'] as $sufiks) {
                $base = $origin . $sufiks;

                if (!in_array($base, $kandidat, true)) {
                    $kandidat[] = $base;
                }
            }
        }

        foreach ($kandidat as $base) {
            if ($this->documentAt($base . $contohPath, $site)) {
                return $base;
            }
        }

        return null;
    }

    /**
     * Periksa keberadaan berkas dokumen dengan permintaan seringan mungkin:
     * HEAD lebih dahulu, GET sepotong bila server tidak melayani HEAD.
     */
    private function documentAt(string $url, SiteConfig $site): bool
    {
        $options = [
            'timeout' => max(5, min(30, $site->requestTimeout)),
            'max_bytes' => 65_536,
            'retry_addresses' => true,
            'headers' => ['Accept' => '*/*'],
        ];

        $head = $this->http->head($url, $options);

        if (self::isDocumentResponse($head, $url)) {
            return true;
        }

        if ($head->status > 0) {
            // Server menjawab (mis. 301 ke halaman HTML / 404): bukan berkas.
            return false;
        }

        return self::isDocumentResponse($this->http->get($url, $options), $url);
    }

    /**
     * Respons ini berkas dokumen? Ya bila status 2xx/3xx dan Content-Type
     * menunjuk jenis dokumen (mis. application/pdf), atau server tidak mengirim
     * Content-Type sementara URL-nya berekstensi dokumen.
     */
    private static function isDocumentResponse(HttpResponse $response, string $url): bool
    {
        if ($response->status < 200 || $response->status >= 400) {
            return false;
        }

        if (SiteConfig::documentExtensionForContentType($response->contentType) !== null) {
            return true;
        }

        return $response->contentType === null && SiteConfig::isDocumentUrl($url);
    }

    /**
     * Endpoint ini bukan sumber daftar dokumen (pelacakan/aksi)?
     */
    private static function skipEndpoint(string $path): bool
    {
        foreach (self::SKIP_PATH_KEYWORDS as $kata) {
            if (str_contains($path, $kata)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Skema + host + port dari URL (kosong bila URL tidak bisa dibaca).
     */
    private static function origin(string $url): string
    {
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
        $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');

        if ($scheme === '' || $host === '') {
            return '';
        }

        $port = (int) (parse_url($url, PHP_URL_PORT) ?? 0);

        return $port > 0 ? $scheme . '://' . $host . ':' . $port : $scheme . '://' . $host;
    }

    /**
     * Kunci pembanding URL (tanpa fragmen, tanpa garis miring akhir).
     */
    private static function urlKey(string $url): string
    {
        $bersih = strtolower(trim(explode('#', $url)[0]));

        return rtrim($bersih, '/');
    }

    /**
     * Apakah kedua host satu keluarga domain (mis. www.kemendagri.go.id dan
     * backend.kemendagri.go.id)? Dipakai supaya endpoint pihak ketiga (analytics,
     * CDN, iklan) tidak dipanggil ulang oleh crawler.
     */
    private static function sameSite(string $a, string $b): bool
    {
        $domainA = self::registrableDomain($a);
        $domainB = self::registrableDomain($b);

        return $domainA !== '' && $domainA === $domainB;
    }

    /**
     * Domain terdaftar sederhana dari host: label terakhir + satu label
     * sebelumnya, ditambah satu label lagi untuk domain publik dua tingkat
     * (go.id, co.id, ac.id, com.au, ...).
     */
    private static function registrableDomain(string $host): string
    {
        $parts = array_values(array_filter(explode('.', strtolower(trim($host))), static fn (string $p): bool => $p !== ''));
        $jumlah = count($parts);

        if ($jumlah <= 2) {
            return implode('.', $parts);
        }

        $kedua = $parts[$jumlah - 2];

        return in_array($kedua, ['co', 'go', 'ac', 'or', 'ne', 'net', 'sch', 'mil', 'gov', 'com', 'edu'], true)
            ? implode('.', array_slice($parts, -3))
            : implode('.', array_slice($parts, -2));
    }
}
