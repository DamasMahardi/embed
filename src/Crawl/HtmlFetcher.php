<?php

declare(strict_types=1);

namespace App\Crawl;

use App\Http\HttpClient;
use App\Http\HttpResponse;
use App\Support\Text;

/**
 * Pengunduh berkas mentah: halaman HTML maupun berkas dokumen
 * (.pdf/.docx/.xlsx/... pada entri bertipe dokumen sites.json).
 *
 * Hasil unduhan ditulis langsung ke file (streaming) supaya berkas besar
 * tidak menahan memori, sekaligus menjadi bukti audit di storage/html atau
 * storage/documents. Setelah diunduh, isi file HTML dikonversi ke UTF-8 agar
 * aman dikirim ke /parse; berkas dokumen dibiarkan apa adanya (biner).
 */
final class HtmlFetcher
{
    /** @var array<string, mixed> */
    private array $crawlConfig;

    /**
     * @param array<string, mixed> $crawlConfig bagian config "crawl"
     */
    public function __construct(
        private HttpClient $http,
        array $crawlConfig = [],
    ) {
        $this->crawlConfig = $crawlConfig;
    }

    /**
     * Unduh $url ke file $targetPath.
     *
     * $document = null: mode ditentukan dari entri site (SiteConfig::isDocument).
     * Pemanggil boleh memaksa true untuk URL dokumen yang ditemukan sebagai
     * TAUTAN pada halaman web (crawl.follow_document_links); URL seperti itu
     * tidak terdeteksi dari entri site yang bertipe web sehingga tanpa flag ini
     * permintaannya memakai header HTML dan berkas PDF-nya ditolak.
     */
    public function fetch(SiteConfig $site, string $url, string $targetPath, ?bool $document = null): FetchResult
    {
        $startedAt = microtime(true);
        $document ??= $site->isDocument($url);

        $response = $this->http->get($url, $this->optionsFor($site, $targetPath, $document));

        return $this->toResult($url, $targetPath, $response, microtime(true) - $startedAt, $document);
    }

    /**
     * Unduh beberapa halaman sekaligus (GET paralel) memakai HttpClient::getMany.
     *
     * Hasil tiap URL identik dengan fetch() satu per satu -- berkas HTML tetap
     * ditulis streaming ke path tujuan dan divalidasi sama -- hanya waktu
     * unduhannya yang tumpang tindih (CRAWL_CONCURRENCY).
     *
     * @param array<string, string> $targets     url => path HTML tujuan
     * @param int                   $concurrency jumlah unduhan bersamaan
     * @param array<string, bool>   $documents   url => true bila URL diperlakukan
     *                                           sebagai berkas dokumen (tidak
     *                                           diisi = ditentukan dari entri site)
     *
     * @return array<string, FetchResult> hasil per URL (kunci = URL asal)
     */
    public function fetchMany(SiteConfig $site, array $targets, int $concurrency = 5, array $documents = []): array
    {
        $requests = [];
        $flags = [];
        foreach ($targets as $url => $path) {
            $url = (string) $url;
            $path = (string) $path;
            $document = $documents[$url] ?? $site->isDocument($url);

            $flags[$url] = $document;
            $requests[] = ['url' => $url, 'options' => $this->optionsFor($site, $path, $document)];
        }

        $startedAt = microtime(true);
        $responses = $this->http->getMany($requests, $concurrency);
        $elapsed = microtime(true) - $startedAt;

        $results = [];
        $index = 0;

        foreach ($targets as $url => $path) {
            $url = (string) $url;
            $path = (string) $path;

            $response = $responses[$index] ?? new HttpResponse($url, 0, [], '', $url, 0.0, 'tidak ada respons', 0, null);
            $duration = $response->durationMs > 0 ? $response->durationMs / 1000 : $elapsed;

            $results[$url] = $this->toResult($url, $path, $response, $duration, $flags[$url] ?? false);
            $index++;
        }

        return $results;
    }

    /**
     * Opsi curl untuk satu unduhan (dipakai fetch() dan fetchMany()).
     *
     * Berkas dokumen: header Accept longgar (bukan HTML) dan batas ukuran
     * terpisah (crawl.document_max_bytes) karena PDF/laporan bisa puluhan MB.
     *
     * @return array<string, mixed>
     */
    private function optionsFor(SiteConfig $site, string $targetPath, bool $document = false): array
    {
        $maxBytes = $document
            ? (int) ($this->crawlConfig['document_max_bytes'] ?? 67_108_864)
            : (int) ($this->crawlConfig['max_bytes'] ?? 5_242_880);

        return [
            'sink' => $targetPath,
            'timeout' => $site->requestTimeout,
            // Batas waktu TAHAP KONEKSI (CRAWLER_CONNECT_TIMEOUT): host dengan
            // beberapa alamat A -- sebagian dibuang firewall -- membuat curl
            // menunggu sampai batas ini habis, jadi HttpClient diberi tahu
            // anggaran waktunya untuk mencoba alamat lain (retry_addresses).
            'connect_timeout' => max(1, (int) ($this->crawlConfig['connect_timeout'] ?? 10)),
            'retry_addresses' => (bool) ($this->crawlConfig['retry_addresses'] ?? true),
            'max_redirects' => (int) ($this->crawlConfig['max_redirects'] ?? 5),
            'max_bytes' => $maxBytes,
            'headers' => $document
                ? [
                    'Accept' => '*/*',
                    'Accept-Language' => 'id-ID,id;q=0.9,en;q=0.8',
                ]
                : [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'id-ID,id;q=0.9,en;q=0.8',
                ],
        ];
    }

    /**
     * Ubah HttpResponse menjadi FetchResult.
     *
     * - HTTP gagal: berkas hasil unduhan dihapus.
     * - Berkas DOKUMEN (pdf/docx/...): disimpan apa adanya (biner, tanpa
     *   konversi UTF-8) dan isHtml=false; /parse memilih parser dari ekstensi.
     * - URL tanpa ekstensi dokumen (mis. "/front/dokumen/download/12",
     *   "/unduh?id=7") yang ternyata mengirim berkas dokumen
     *   (Content-Type application/pdf, xlsx, ...): berkas diganti namanya sesuai
     *   Content-Type lalu diperlakukan seperti berkas dokumen.
     * - URL dokumen yang ternyata mengirim HTML (mis. redirect ke halaman):
     *   berkas diganti nama menjadi .html lalu diperlakukan seperti halaman.
     * - Selain HTML (dan bukan berkas dokumen): gagal, berkas dihapus.
     */
    private function toResult(
        string $url,
        string $targetPath,
        HttpResponse $response,
        float $duration,
        bool $document = false,
    ): FetchResult {
        if (!$response->ok()) {
            @unlink($targetPath);

            $message = $response->status > 0
                ? 'HTTP ' . $response->status . ' (' . $response->errorMessage(160) . ')'
                : (string) $response->errorMessage(160);

            return FetchResult::failure(
                $url,
                $response->status,
                $message,
                $duration,
                $response->remoteIp,
                $response->addressRetry,
            );
        }

        $contentType = $response->contentType;
        $charset = Text::charsetFromContentType($contentType);
        $isHtml = $this->looksLikeHtml($contentType, $targetPath);

        // Respons non-HTML: tentukan jenis berkasnya dari Content-Type,
        // Content-Disposition, atau isi berkas (magic bytes) karena endpoint
        // unduhan banyak yang mengirim "application/octet-stream" (mis.
        // /front/dokumen/download/400470451). Berkas dinamai sesuai ekstensi
        // hasil deteksi supaya /parse memilih parser yang benar
        // (parse_pdf.py untuk .pdf); Pipeline lalu memindahkannya ke
        // storage/documents dan memprosesnya sebagai dokumen.
        //
        // Deteksi ini WAJIB berlaku juga untuk URL yang sudah diperkirakan
        // dokumen dari katanya saja ("/unduh?id=7", "/front/dokumen/download/12")
        // karena URL seperti itu tidak punya ekstensi berkas.
        if (!$isHtml) {
            $extension = $this->documentExtensionFor(
                $targetPath,
                $contentType,
                $response->header('content-disposition')
            );

            if ($extension !== null) {
                $document = true;
                $targetPath = $this->renameTo($targetPath, $extension);
            }
        }

        if ($document && !$isHtml) {
            $size = @filesize($targetPath);

            if ($size === false || $size === 0) {
                @unlink($targetPath);

                return FetchResult::failure(
                    $url,
                    $response->status,
                    'berkas dokumen kosong (content-type=' . ($contentType ?? 'tidak ada') . ')',
                    $duration
                );
            }

            return new FetchResult(
                url: $url,
                effectiveUrl: $response->effectiveUrl,
                httpStatus: $response->status,
                ok: true,
                isHtml: false,
                contentType: $contentType,
                bytes: (int) $size,
                durationSeconds: $duration,
                path: $targetPath,
                charset: null,
                error: null,
                insecureTls: $response->insecureTls,
                remoteIp: $response->remoteIp,
                addressRetry: $response->addressRetry,
            );
        }

        if (!$isHtml) {
            @unlink($targetPath);

            return FetchResult::failure(
                $url,
                $response->status,
                'bukan HTML (content-type=' . ($contentType ?? 'tidak ada') . ')',
                $duration
            );
        }

        if ($document) {
            // URL .pdf/.docx (atau endpoint unduhan) yang isinya HTML: simpan
            // dengan ekstensi .html supaya /parse tidak salah memilih parser.
            $targetPath = $this->renameTo($targetPath, 'html');
        }

        $bytes = $this->normalizeToUtf8($targetPath, $charset);
        if ($bytes < 0) {
            @unlink($targetPath);

            return FetchResult::failure($url, $response->status, 'file HTML hasil unduhan tidak bisa dibaca', $duration);
        }

        return new FetchResult(
            url: $url,
            effectiveUrl: $response->effectiveUrl,
            httpStatus: $response->status,
            ok: true,
            isHtml: true,
            contentType: $contentType,
            bytes: $bytes,
            durationSeconds: $duration,
            path: $targetPath,
            charset: $charset,
            error: null,
            insecureTls: $response->insecureTls,
            remoteIp: $response->remoteIp,
            addressRetry: $response->addressRetry,
        );
    }

    /**
     * Baca isi file HTML sebagai UTF-8 (helper untuk proses turunan).
     */
    public function read(string $path): string
    {
        $contents = @file_get_contents($path);

        return $contents === false ? '' : $contents;
    }

    /**
     * Ganti ekstensi berkas hasil unduhan: ".html" untuk respons HTML pada URL
     * dokumen, atau ekstensi Content-Type (".pdf") untuk endpoint tanpa
     * ekstensi yang mengirim berkas dokumen. Mengembalikan path hasil, atau
     * path asal bila penggantian nama gagal.
     */
    private function renameTo(string $path, string $extension): string
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === strtolower($extension)) {
            return $path;
        }

        $newPath = preg_replace('/\.[a-z0-9]{1,5}$/i', '', $path);
        if (!is_string($newPath) || $newPath === $path) {
            $newPath = $path . '.' . $extension;
        } else {
            $newPath .= '.' . $extension;
        }

        return @rename($path, $newPath) ? $newPath : $path;
    }

    private function looksLikeHtml(?string $contentType, string $path): bool
    {
        if ($contentType === null || $contentType === '') {
            // Beberapa server tidak mengirim Content-Type; percayai isi file.
            $head = (string) @file_get_contents($path, false, null, 0, 1024);

            return stripos($head, '<html') !== false || stripos($head, '<!doctype') !== false;
        }

        $lower = strtolower($contentType);

        return str_contains($lower, 'text/html')
            || str_contains($lower, 'application/xhtml')
            || str_contains($lower, 'text/plain')
            || str_contains($lower, 'text/xml')
            || str_contains($lower, 'application/xml');
    }

    /**
     * Ekstensi berkas dokumen untuk respons non-HTML. Urutan pemeriksaan:
     *   1. Content-Type yang dikenal (application/pdf, ...),
     *   2. Content-Disposition (mis. attachment; filename="Laporan.pdf"),
     *   3. isi berkas (magic bytes "%PDF-", arsip Office PK, OLE, "{\rtf").
     *
     * Langkah 2 dan 3 penting karena endpoint unduhan banyak yang mengirim
     * "application/octet-stream" (tanpa ekstensi pada URL) seperti
     * https://ppid.kemendagri.go.id/front/dokumen/download/400470451.
     * null = bukan berkas dokumen (gambar/arsip/video) sehingga unduhan ditolak
     * seperti sebelumnya.
     */
    private function documentExtensionFor(string $path, ?string $contentType, ?string $disposition): ?string
    {
        $extension = SiteConfig::documentExtensionForContentType($contentType);

        if ($extension === null) {
            $extension = self::extensionFromDisposition($disposition);
        }

        return $extension ?? self::extensionFromContent($path);
    }

    /**
     * Ekstensi dari header Content-Disposition, mis.
     * "attachment; filename=\"Tugas dan Fungsi DPM PTSP.pdf\"" -> "pdf".
     */
    private static function extensionFromDisposition(?string $disposition): ?string
    {
        if ($disposition === null || $disposition === '') {
            return null;
        }

        // Bentuk yang didukung: filename="a.pdf", filename=a.pdf,
        // filename*=UTF-8''a%20b.pdf
        if (preg_match_all('/\bfilename\*?\s*=\s*(?:UTF-8\'\')?([^;]+)/i', $disposition, $matches) === 0) {
            return null;
        }

        foreach ($matches[1] as $name) {
            $name = strtolower(rawurldecode(trim($name, " \t\"'")));
            $extension = pathinfo($name, PATHINFO_EXTENSION);

            if ($extension !== '' && in_array($extension, SiteConfig::DOCUMENT_EXTENSIONS, true)) {
                return $extension;
            }
        }

        return null;
    }

    /**
     * Ekstensi dari isi berkas (magic bytes), dipakai bila Content-Type dan
     * Content-Disposition tidak memberi petunjuk.
     */
    private static function extensionFromContent(string $path): ?string
    {
        $head = (string) @file_get_contents($path, false, null, 0, 8192);

        if (str_starts_with($head, '%PDF-')) {
            return 'pdf';
        }

        if (str_starts_with($head, 'PK')) {
            // Office baru (docx/xlsx/pptx) = arsip ZIP; nama folder entri
            // pertama menyebut aplikasinya. Arsip ZIP lain (zip/odt/gambar)
            // sengaja TIDAK dianggap dokumen supaya unduhan sampah tidak
            // dikirim ke /parse.
            if (str_contains($head, 'word/')) {
                return 'docx';
            }

            if (str_contains($head, 'xl/')) {
                return 'xlsx';
            }

            if (str_contains($head, 'ppt/')) {
                return 'pptx';
            }

            return null;
        }

        // Office lama (OLE): doc/xls/ppt dibedakan dari nama stream-nya.
        if (str_starts_with($head, "\xD0\xCF\x11\xE0")) {
            if (str_contains($head, 'WordDocument')) {
                return 'doc';
            }

            if (str_contains($head, 'Workbook')) {
                return 'xls';
            }

            if (str_contains($head, 'PowerPoint')) {
                return 'ppt';
            }

            return null;
        }

        if (str_starts_with(ltrim($head, " \t\r\n"), '{\\rtf')) {
            return 'rtf';
        }

        return null;
    }

    /**
     * Konversi isi file ke UTF-8 bila perlu. Mengembalikan ukuran file
     * (byte) atau -1 bila gagal.
     */
    private function normalizeToUtf8(string $path, ?string $charset): int
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return -1;
        }

        $utf8 = Text::toUtf8($raw, $charset);

        if ($utf8 !== $raw) {
            // Pertahankan deklarasi charset agar parser di sisi service tidak
            // salah menebak (mis. "iso-8859-1" padahal isi sudah UTF-8).
            $utf8 = preg_replace(
                '/<meta([^>]*?)charset\s*=\s*["\']?[a-z0-9_\-]+["\']?/i',
                '<meta$1charset="UTF-8"',
                $utf8,
                1
            ) ?? $utf8;

            @file_put_contents($path, $utf8, LOCK_EX);
        }

        return strlen($utf8);
    }
}
