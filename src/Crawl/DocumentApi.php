<?php

declare(strict_types=1);

namespace App\Crawl;

use App\Http\HttpClient;
use App\Support\Text;

/**
 * Pengumpul DAFTAR BERKAS DOKUMEN dari API JSON milik situs (aturan
 * "document_api" pada config/sites.json).
 *
 * Sebagian situs menyembunyikan tautan unduhan di balik JavaScript: halaman
 * hanya memuat judul bab, sedangkan daftar sub-item beserta berkas PDF-nya baru
 * diminta browser lewat API. Contoh nyata: halaman /informasi-publik di
 * www.kemendagri.go.id memanggil
 * https://backend.kemendagri.go.id/api/v1/informasi-publik/public dan berkasnya
 * disajikan di https://backend.kemendagri.go.id/uploads/files/... sehingga
 * tautannya tidak pernah ada di HTML/DOM dan tidak bisa ditemukan crawler
 * sendiri. Aturan "document_api" memberi tahu crawler endpoint JSON tersebut
 * beserta field yang berisi path/URL berkas, sehingga berkasnya ikut diunduh ->
 * /parse -> /embed -> Qdrant.
 *
 * Bentuk satu aturan (hanya "url" yang wajib):
 *   {
 *     "url": "https://backend.contoh.go.id/api/v1/informasi-publik/public",
 *     "items_field": "data.informasi",  // jalur field berisi daftar item ("" = seluruh badan respons)
 *     "children_field": "children",     // nama field anak (bawaan "children")
 *     "link_field": "file",             // jalur field berisi path/URL berkas (bawaan "file")
 *     "title_field": "item",            // jalur field judul (opsional, dipakai log)
 *     "base_url": "https://backend.contoh.go.id/uploads", // awalan path relatif ("/files/a.pdf")
 *     "extensions": ["pdf", "docx"],    // batas ekstensi (kosong = semua DOCUMENT_EXTENSIONS)
 *     "max_documents": 200,             // batas jumlah berkas satu aturan
 *     "timeout": 30                     // detik; 0 = request_timeout site
 *   }
 *
 * URL berkas boleh berada di host lain (memang sering begitu, mis. backend.*);
 * Pipeline yang menentukan pemeriksaan host/robots untuk URL hasil API ini.
 *
 * Semua kegagalan (URL bukan http(s), respons bukan JSON, field kosong) hanya
 * menghasilkan daftar kosong: crawl halaman lain tidak terganggu dan sebabnya
 * dicatat pada log.
 */
final class DocumentApi
{
    /** Batas ukuran respons JSON yang dibaca (metadata + daftar berkas situs besar). */
    public const MAX_BYTES = 8_388_608;

    public function __construct(
        private readonly HttpClient $http,
    ) {
    }

    public function hasRules(SiteConfig $site): bool
    {
        return $site->documentApi !== [];
    }

    /**
     * Ambil berkas dari SEMUA aturan site ini.
     *
     * @return array{
     *     berkas: array<string, string>,
     *     laporan: list<array{endpoint: string, http: int, jumlah: int, galat: string}>
     * } "berkas" = url => judul (judul boleh ""), "laporan" = ringkasan per aturan
     */
    public function collect(SiteConfig $site): array
    {
        $berkas = [];
        $laporan = [];

        foreach ($site->documentApi as $rule) {
            $hasil = $this->fetch($rule, $site);

            $laporan[] = [
                'endpoint' => (string) ($rule['url'] ?? ''),
                'http' => $hasil['http'],
                'jumlah' => count($hasil['berkas']),
                'galat' => $hasil['galat'],
            ];

            foreach ($hasil['berkas'] as $url => $judul) {
                if (!isset($berkas[$url])) {
                    $berkas[$url] = $judul;
                }
            }
        }

        return ['berkas' => $berkas, 'laporan' => $laporan];
    }

    /**
     * Baca satu endpoint aturan dan kumpulkan berkas di dalamnya.
     *
     * @param array<string, mixed> $rule aturan ternormalisasi (SiteConfig::documentApiRules)
     *
     * @return array{berkas: array<string, string>, http: int, galat: string}
     */
    private function fetch(array $rule, SiteConfig $site): array
    {
        $endpoint = trim((string) ($rule['url'] ?? ''));

        if (preg_match('#^https?://#i', $endpoint) !== 1) {
            return ['berkas' => [], 'http' => 0, 'galat' => 'url API bukan http(s) absolut'];
        }

        // Aturan document_api dipanggil TANPA konteks halaman, jadi placeholder
        // seperti {slug} tidak punya nilai: lebih baik dilewati dengan catatan
        // daripada memanggil URL yang salah.
        if (preg_match('/\{[a-z0-9]+\}/i', $endpoint) === 1) {
            return ['berkas' => [], 'http' => 0, 'galat' => 'url API berisi placeholder yang tidak didukung'];
        }

        $timeout = max(0, (int) ($rule['timeout'] ?? 0));

        $response = $this->http->get($endpoint, [
            'timeout' => $timeout > 0 ? $timeout : $site->requestTimeout,
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

        $itemsField = (string) ($rule['items_field'] ?? '');
        $items = $itemsField === '' ? $json : ContentApi::dig($json, $itemsField);

        if (!is_array($items)) {
            return [
                'berkas' => [],
                'http' => $response->status,
                'galat' => 'field "' . ($itemsField === '' ? '<badan>' : $itemsField) . '" tidak berisi daftar',
            ];
        }

        $rule['_endpoint'] = $endpoint;
        $berkas = [];
        $this->walk($items, $rule, $berkas, 0);

        return ['berkas' => $berkas, 'http' => $response->status, 'galat' => ''];
    }

    /**
     * Telusuri struktur JSON (induk -> children -> cucu -> ...) dan kumpulkan
     * berkas yang ditemukan pada link_field.
     *
     * @param mixed                 $node
     * @param array<string, mixed>  $rule
     * @param array<string, string> $berkas url => judul
     */
    private function walk(mixed $node, array $rule, array &$berkas, int $depth): void
    {
        if (!is_array($node) || $depth > 6) {
            return;
        }

        $linkField = (string) ($rule['link_field'] ?? 'file');
        $childrenField = (string) ($rule['children_field'] ?? 'children');
        $maxDocuments = max(1, (int) ($rule['max_documents'] ?? 200));

        // Nilai yang diacu items_field boleh berupa SATU item (bukan daftar).
        if ($linkField !== '' && array_key_exists($linkField, $node)) {
            $this->consider($node, $rule, $berkas);
        }

        foreach ($node as $item) {
            if (count($berkas) >= $maxDocuments) {
                return;
            }

            if (!is_array($item)) {
                continue;
            }

            $this->consider($item, $rule, $berkas);

            if ($childrenField === '') {
                continue;
            }

            $children = ContentApi::dig($item, $childrenField);
            if (is_array($children)) {
                $this->walk($children, $rule, $berkas, $depth + 1);
            }
        }
    }

    /**
     * Catat berkas satu item (bila link_field-nya berisi path/URL dokumen).
     *
     * @param array<string, mixed>  $item
     * @param array<string, mixed>  $rule
     * @param array<string, string> $berkas
     */
    private function consider(array $item, array $rule, array &$berkas): void
    {
        $linkField = (string) ($rule['link_field'] ?? 'file');
        $link = $linkField === '' ? null : ContentApi::dig($item, $linkField);

        if (!is_string($link) || trim($link) === '') {
            return;
        }

        $url = self::fileUrl(trim($link), (string) ($rule['base_url'] ?? ''), (string) ($rule['_endpoint'] ?? ''));

        if ($url === '' || isset($berkas[$url]) || !self::allowed($url, $rule)) {
            return;
        }

        $titleField = (string) ($rule['title_field'] ?? '');
        $judul = $titleField === '' ? null : ContentApi::dig($item, $titleField);

        $berkas[$url] = is_scalar($judul) ? Text::oneLine((string) $judul, 240) : '';
    }

    /**
     * Susun URL berkas dari nilai link_field: URL absolut dipakai apa adanya,
     * path relatif digabung dengan "base_url" (atau dengan host endpoint API).
     */
    private static function fileUrl(string $link, string $base, string $endpoint): string
    {
        if (preg_match('#^https?://#i', $link) === 1) {
            return $link;
        }

        $base = rtrim(trim($base), '/');

        if ($base === '' && $endpoint !== '') {
            $skema = (string) (parse_url($endpoint, PHP_URL_SCHEME) ?? '');
            $host = (string) (parse_url($endpoint, PHP_URL_HOST) ?? '');
            $base = $skema !== '' && $host !== '' ? $skema . '://' . $host : '';
        }

        if ($base === '') {
            return '';
        }

        return $base . (str_starts_with($link, '/') ? $link : '/' . $link);
    }

    /**
     * Apakah URL ini berkas yang diinginkan aturan? Bawaan: semua ekstensi
     * dokumen yang dikenal crawler (pdf/docx/xlsx/...) atau endpoint unduhan
     * tanpa ekstensi.
     *
     * @param array<string, mixed> $rule
     */
    private static function allowed(string $url, array $rule): bool
    {
        $extensions = array_values(array_filter(
            array_map(
                static fn ($item): string => strtolower(trim((string) $item)),
                (array) ($rule['extensions'] ?? [])
            ),
            static fn (string $item): bool => $item !== ''
        ));

        if ($extensions === []) {
            return SiteConfig::isDocumentUrl($url) || SiteConfig::looksLikeDocumentEndpoint($url);
        }

        return in_array(SiteConfig::extensionFor($url), $extensions, true);
    }
}
