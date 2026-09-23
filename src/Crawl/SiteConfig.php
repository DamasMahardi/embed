<?php

declare(strict_types=1);

namespace App\Crawl;

use App\Support\Text;

/**
 * Satu target yang akan diproses (hasil merge "defaults" + entry di
 * config/sites.json). Objek ini immutable supaya satu run tidak mengubah
 * konfigurasi site lain.
 *
 * Dua bentuk entri didukung:
 *   - DOKUMEN: satu URL berkas/halaman, mis.
 *     {"url": ".../laporan-anggaran-2024.pdf", "documentType": "APBD", ...}
 *     URL berakhiran ekstensi dokumen (pdf/docx/xlsx/...) otomatis masuk mode
 *     dokumen: satu unduhan, tanpa menelusuri tautan.
 *   - WEB: crawl berantai dengan start_urls + max_pages/max_depth.
 *
 * Bentuk ringkas yang dipakai config/sites.json (satu objek = satu target;
 * semua field metadata ditulis apa adanya, isi "" bila belum ada):
 *
 *   {
 *     "url": "https://www.kemendagri.go.id/",
 *     "documentType": "",
 *     "province": "DKI Jakarta",
 *     "city": "",
 *     "year": 2026
 *   }
 *
 * Field "id" dan "name" bersifat opsional: id otomatis dibuat dari host URL
 * (lihat idForUrl()) dan "name" hanya untuk menimpa label tampilan pada log,
 * perintah "list", serta site_name di payload vektor. Nilai metadata yang
 * kosong ("") diabaikan, jadi entri web tanpa documentType/city tidak pernah
 * menghasilkan label tahun saja seperti "2026".
 *
 * Ukuran chunk bawaan 12000 karakter (tumpang tindih 1200) supaya satu point
 * Qdrant memuat konteks yang cukup banyak seperti pipeline dokumen lain; nilai
 * itu bisa ditimpa per entri lewat "chunk_size"/"chunk_overlap".
 *
 * Situs yang isi halamannya baru dibuat di browser (mis. Next.js App Router)
 * hanya mengirim menu/tagline/footer saat HTML diunduh tanpa JavaScript.
 * Penanganan bawaannya: halaman kerangka seperti itu dirender lebih dahulu
 * dengan browser headless lewat field "js_render" (lihat App\Crawl\HeadlessRenderer),
 * sehingga isi halaman sebenarnya ikut terbaca tanpa aturan API per situs.
 * Sebagai cadangan, entri boleh menulis "content_api" (isi halaman diambil dari
 * API resmi situs, lihat App\Crawl\ContentApi). "drop_duplicate_content" membuang
 * isi yang identik/mirip dengan URL lain pada site yang sama sehingga isi
 * boilerplate tidak pernah terkirim ke Qdrant, dan "duplicate_similarity"
 * mengatur seberapa mirip dua halaman dianggap sama (1.0 = hanya identik).
 */
final class SiteConfig
{
    /**
     * Ekstensi yang diperlakukan sebagai berkas DOKUMEN: diunduh apa adanya
     * (tanpa dikonversi UTF-8) dan diparse oleh layanan /parse sesuai
     * ekstensinya (mis. parse_pdf.py untuk .pdf).
     */
    public const DOCUMENT_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'rtf', 'csv',
    ];

    /**
     * Content-Type berkas dokumen -> ekstensi berkas.
     *
     * Dipakai untuk URL dokumen TANPA ekstensi (mis.
     * "/front/dokumen/download/400470451", "/unduh?id=12"): respons dengan
     * Content-Type di bawah ini tetap diperlakukan sebagai berkas dokumen dan
     * berkasnya dinamai sesuai ekstensi ini supaya layanan /parse memilih parser
     * yang tepat (mis. parse_pdf.py untuk application/pdf).
     */
    public const DOCUMENT_MIME_EXTENSIONS = [
        'application/pdf' => 'pdf',
        'application/x-pdf' => 'pdf',
        'application/acrobat' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/vnd.oasis.opendocument.text' => 'odt',
        'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
        'application/rtf' => 'rtf',
        'text/rtf' => 'rtf',
        'text/csv' => 'csv',
        'application/csv' => 'csv',
    ];

    /**
     * Kata kunci segmen path/kueri yang biasanya menandai ENDPOINT UNDUHAN
     * berkas tanpa ekstensi (mis. "/front/dokumen/download/12", "/unduh?id=7").
     * Hanya dipakai bila crawl.follow_document_links aktif.
     */
    private const DOCUMENT_ENDPOINT_KEYWORDS = [
        'download', 'unduh', 'unduhan', 'berkas', 'lampiran', 'attachment',
        'getfile', 'get-file', 'filedownload', 'downloadfile', 'download-file',
    ];

    /** Nilai field "kind" yang memaksa mode dokumen. */
    private const DOCUMENT_KINDS = ['document', 'dokumen', 'file', 'berkas', 'lampiran', 'attachment'];

    /** Nilai field "kind" yang memaksa mode web (crawl berantai). */
    private const WEB_KINDS = ['web', 'halaman', 'page', 'html'];

    /**
     * @param list<string>         $startUrls
     * @param list<string>         $includePatterns regex PCRE tanpa delimiter
     * @param list<string>         $excludePatterns
     * @param array<string, mixed> $metadata        metadata dokumen (ikut ke payload vektor)
     * @param bool                 $documentMode    true = entri menunjuk dokumen
     *                                              (bukan halaman web berantai)
     * @param float                $maxLinkRatio   batas rasio markup tautan per
     *                                              chunk (0.0-1.0, 1 = bebas)
     * @param bool                 $cleanMarkdown  bersihkan menu/ornament/footer
     *                                              pada markdown hasil /parse
     * @param bool                 $dropDuplicateContent buang isi halaman yang
     *                                              identik/mirip dengan URL lain
     *                                              pada site yang sama
     *                                              (boilerplate situs berbasis
     *                                              JavaScript)
     * @param float                $duplicateSimilarity ambang kemiripan isi
     *                                              (0.0-1.0; 1.0 = hanya identik)
     * @param array<string, mixed> $jsRender       setelan render browser headless
     *                                              (mode off|auto|always, wait_ms,
     *                                              timeout_ms, bin, flags,
     *                                              user_agent, min_text, ratio)
     * @param list<array<string, mixed>> $contentApi aturan "content_api": cadangan
     *                                              isi halaman dari API resmi
     *                                              situs bila render tidak mungkin
     * @param list<array<string, mixed>> $documentApi aturan "document_api":
     *                                              daftar BERKAS DOKUMEN dari API
     *                                              resmi situs (tautan unduhan yang
     *                                              baru dibuat JavaScript)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly bool $enabled,
        public readonly array $startUrls,
        public readonly int $maxPages,
        public readonly int $maxDepth,
        public readonly bool $followLinks,
        public readonly bool $sameHostOnly,
        public readonly int $rateLimitMs,
        public readonly bool $respectRobots,
        public readonly int $requestTimeout,
        public readonly bool $saveHtml,
        public readonly int $chunkSize,
        public readonly int $chunkOverlap,
        public readonly int $minChunkLength,
        public readonly int $embedBatchSize,
        public readonly array $includePatterns = [],
        public readonly array $excludePatterns = [],
        public readonly array $metadata = [],
        public readonly ?string $userAgent = null,
        public readonly bool $documentMode = false,
        public readonly float $maxLinkRatio = 0.6,
        public readonly bool $cleanMarkdown = true,
        public readonly bool $dropDuplicateContent = true,
        public readonly float $duplicateSimilarity = 0.85,
        public readonly array $jsRender = [],
        public readonly array $contentApi = [],
        public readonly array $documentApi = [],
        public readonly bool $deleteDocumentsAfterPush = true,
        public readonly bool $autoDocuments = true,
        /**
         * Jelajahi situs LAIN yang ditautkan halaman situs ini:
         *   'off'    = hanya host pada start_urls (perilaku lama);
         *   'family' = hanya host satu keluarga domain (mis. halaman
         *              www.kemendagri.go.id menautkan otda.kemendagri.go.id,
         *              polpum.kemendagri.go.id, ...);
         *   'all'    = semua host http(s) yang ditemukan (dibatasi
         *              crawl.follow_external_max_hosts).
         * Nilai bawaan entri diambil dari "follow_external" pada
         * config/sites.json / CRAWLER_FOLLOW_EXTERNAL; satu run bisa
         * menimpanya lewat opsi --follow-external=...
         */
        public readonly string $followExternal = 'off',
        /**
         * Ikuti tautan Google Drive pada halaman yang di-crawl: folder publik
         * dibaca isinya (embeddedfolderview / payload _DRIVE_ivd), lalu setiap
         * berkasnya diunduh -> /parse -> /embed -> Qdrant. Berkas Google Docs
         * (document/spreadsheets/presentation) diekspor ke docx/xlsx/pptx.
         * Boleh dimatikan per entri sites.json: "google_drive": false.
         */
        public readonly bool $googleDrive = true,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $defaults
     */
    public static function fromArray(array $data, array $defaults = []): self
    {
        $pick = static function (array $keys, mixed $fallback = null) use ($data, $defaults): mixed {
            foreach ($keys as $key) {
                if (array_key_exists($key, $data) && $data[$key] !== null) {
                    return $data[$key];
                }
            }

            foreach ($keys as $key) {
                if (array_key_exists($key, $defaults) && $defaults[$key] !== null) {
                    return $defaults[$key];
                }
            }

            return $fallback;
        };

        // Nilai eksplisit pada entri (bukan pada "defaults") dipakai untuk
        // menentukan apakah pengguna sudah menentukan sendiri nilainya.
        $explicit = static function (array $keys) use ($data): bool {
            foreach ($keys as $key) {
                if (array_key_exists($key, $data)) {
                    return true;
                }
            }

            return false;
        };

        $id = Text::slug((string) $pick(['id', 'slug'], 'site'));

        $startUrls = $pick(['start_urls', 'startUrls', 'urls', 'url'], []);
        if (is_string($startUrls)) {
            $startUrls = [$startUrls];
        }
        $startUrls = array_values(array_filter(
            array_map(static fn ($url): string => trim((string) $url), (array) $startUrls),
            static fn (string $url): bool => $url !== ''
        ));

        $metadata = $pick(['metadata', 'meta'], []);
        if (!is_array($metadata)) {
            $metadata = [];
        }

        // Metadata boleh juga ditulis langsung di level site (document_type,
        // province, city, year) seperti gaya sources.json di crawl-web.
        foreach (['document_type', 'documentType', 'province', 'city', 'year'] as $key) {
            if (array_key_exists($key, $data) && !array_key_exists($key, $metadata)) {
                $metadata[$key] = $data[$key];
            }
        }

        // Metadata yang ditulis kosong ("") tidak dipakai: pada bentuk ringkas
        // entri web selalu menulis "documentType": "" dan "city": "", dan nilai
        // kosong itu tidak boleh ikut ke payload vektor (sekaligus menjaga
        // documentType() tetap memakai nilai bawaan WEB sehingga sectionLabel()
        // tidak berubah menjadi tahun saja, mis. "2026").
        foreach ($metadata as $key => $value) {
            if (is_string($value) && trim($value) === '') {
                unset($metadata[$key]);
            }
        }

        // Nama tampilan: field eksplisit -> label metadata ("APBD Kabupaten
        // Serang 2024") -> host URL ("kemendagri.go.id") -> id site. Tahun
        // saja (mis. 2026) tidak pernah dipakai sebagai nama karena bukan nama,
        // itulah sebabnya dahulu kolom "Nama" di dashboard bisa berisi "2026".
        if ($explicit(['name', 'label', 'title', 'judul'])) {
            $name = (string) $pick(['name', 'label', 'title', 'judul'], $id);
        } else {
            $name = self::labelFromMetadata($metadata) ?? self::labelFromUrls($startUrls) ?? $id;
        }

        // Mode DOKUMEN: entri sites.json boleh langsung menunjuk satu berkas
        // (mis. laporan-anggaran-2024.pdf) atau satu halaman tertentu. URL
        // berekstensi dokumen otomatis dianggap dokumen; field "kind" dapat
        // memaksa "document" atau "web".
        $kind = strtolower(trim((string) $pick(['kind', 'jenis', 'mode'], '')));
        $documentMode = $startUrls !== []
            && count(array_filter($startUrls, static fn (string $url): bool => self::isDocumentUrl($url))) === count($startUrls);

        if (in_array($kind, self::DOCUMENT_KINDS, true)) {
            $documentMode = true;
        } elseif (in_array($kind, self::WEB_KINDS, true)) {
            $documentMode = false;
        }

        $userAgent = $pick(['user_agent', 'userAgent'], null);

        // Setelan render browser headless: nilai pada entri menimpa "defaults"
        // (config/sites.json) yang sudah digabung setelan config/app.php.
        $jsRenderDefault = is_array($defaults['js_render'] ?? null) ? $defaults['js_render'] : [];
        $jsRender = self::jsRenderConfig($pick(['js_render', 'jsRender'], null), $jsRenderDefault);

        return new self(
            id: $id,
            name: $name,
            enabled: (bool) $pick(['enabled'], true),
            startUrls: $startUrls,
            // 0 = TANPA BATAS (bawaan untuk site web): semua halaman yang
            // ditemukan diproses. Entri dokumen tetap dibatasi 1 URL karena
            // satu berkas = satu target.
            maxPages: max(0, (int) ($documentMode && !$explicit(['max_pages', 'maxPages'])
                ? 1
                : $pick(['max_pages', 'maxPages'], $documentMode ? 1 : 0))),
            maxDepth: max(-1, (int) ($documentMode && !$explicit(['max_depth', 'maxDepth'])
                ? 0
                : $pick(['max_depth', 'maxDepth'], $documentMode ? 0 : 1))),
            followLinks: (bool) ($documentMode && !$explicit(['follow_links', 'followLinks'])
                ? false
                : $pick(['follow_links', 'followLinks'], !$documentMode)),
            sameHostOnly: (bool) $pick(['same_host_only', 'sameHostOnly'], true),
            rateLimitMs: max(0, (int) $pick(['rate_limit_ms', 'rateLimitMs'], 1000)),
            respectRobots: (bool) $pick(['respect_robots_txt', 'respectRobotsTxt'], true),
            requestTimeout: max(5, (int) $pick(['request_timeout', 'requestTimeout'], 60)),
            saveHtml: (bool) $pick(['save_html', 'saveHtml'], true),
            chunkSize: max(200, (int) $pick(['chunk_size', 'chunkSize'], 12000)),
            chunkOverlap: max(0, (int) $pick(['chunk_overlap', 'chunkOverlap'], 1200)),
            minChunkLength: max(0, (int) $pick(['min_chunk_length', 'minChunkLength'], 50)),
            embedBatchSize: max(1, (int) $pick(['embed_batch_size', 'embedBatchSize'], 8)),
            includePatterns: self::stringList(
                $documentMode && !$explicit(['include_patterns', 'includePatterns'])
                    ? []
                    : $pick(['include_patterns', 'includePatterns'], [])
            ),
            excludePatterns: self::stringList(
                $documentMode && !$explicit(['exclude_patterns', 'excludePatterns'])
                    ? []
                    : $pick(['exclude_patterns', 'excludePatterns'], [])
            ),
            metadata: $metadata,
            userAgent: $userAgent === null ? null : (string) $userAgent,
            documentMode: $documentMode,
            maxLinkRatio: self::ratio($pick(['max_link_ratio', 'maxLinkRatio'], 0.6)),
            cleanMarkdown: (bool) $pick(['clean_markdown', 'cleanMarkdown'], true),
            dropDuplicateContent: (bool) $pick(['drop_duplicate_content', 'dropDuplicateContent'], true),
            duplicateSimilarity: (float) ($defaults['duplicate_similarity'] ?? 0.85),
            jsRender: $jsRender,
            contentApi: self::contentApiRules($pick(['content_api', 'contentApi'], [])),
            documentApi: self::documentApiRules($pick(['document_api', 'documentApi'], [])),
            deleteDocumentsAfterPush: (bool) $pick(
                ['delete_documents_after_push', 'deleteDocumentsAfterPush'],
                $defaults['delete_documents_after_push'] ?? true
            ),
            autoDocuments: (bool) $pick(
                ['auto_documents', 'autoDocuments'],
                $defaults['auto_documents'] ?? true
            ),
            followExternal: self::followExternalMode($pick(
                ['follow_external', 'followExternal'],
                $defaults['follow_external'] ?? 'off'
            )),
            googleDrive: (bool) $pick(
                ['google_drive', 'googleDrive'],
                $defaults['google_drive'] ?? true
            ),
        );
    }

    /**
     * Normalisasi setelan "js_render" (render halaman dengan browser headless).
     *
     * Bentuk yang diterima:
     *   - false / "off"   : halaman kerangka diproses seperti biasa
     *   - true / "always" : setiap halaman HTML dirender
     *   - "auto" (bawaan) : hanya halaman kerangka SPA yang dirender
     *   - {"mode": "auto", "wait_ms": 6000, "timeout_ms": 25000, "bin": "",
     *      "flags": "...", "user_agent": "...", "min_text": 2000, "ratio": 0.03}
     *
     * @param mixed                $value    nilai dari config/sites.json
     * @param array<string, mixed> $fallback setelan dari config/app.php / defaults
     *
     * @return array<string, mixed>
     */
    public static function jsRenderConfig(mixed $value, array $fallback = []): array
    {
        $config = HeadlessRenderer::defaults();

        foreach ($fallback as $key => $isi) {
            if ($isi !== null && array_key_exists($key, $config)) {
                $config[$key] = $isi;
            }
        }

        $nilaiMode = is_array($value)
            ? ($value['mode'] ?? ($value['enabled'] ?? null))
            : $value;
        $mode = HeadlessRenderer::modeValue($nilaiMode);

        if ($mode !== null) {
            $config['mode'] = $mode;
        }

        if (!is_array($value)) {
            return $config;
        }

        $peta = [
            'wait_ms' => ['wait_ms', 'wait', 'render_wait_ms'],
            'timeout_ms' => ['timeout_ms', 'timeout', 'render_timeout_ms'],
            'bin' => ['bin', 'browser', 'browser_bin', 'browser_path'],
            'flags' => ['flags', 'args', 'browser_flags', 'extra_flags'],
            'user_agent' => ['user_agent', 'userAgent', 'browser_ua'],
            'min_text' => ['min_text', 'min_text_chars', 'render_min_text'],
            'ratio' => ['ratio', 'max_text_ratio', 'render_max_text_ratio'],
        ];

        foreach ($peta as $target => $keys) {
            foreach ($keys as $key) {
                if (!array_key_exists($key, $value) || $value[$key] === null) {
                    continue;
                }

                $config[$target] = match ($target) {
                    'wait_ms', 'timeout_ms', 'min_text' => max(0, (int) $value[$key]),
                    'ratio' => min(1.0, max(0.0, (float) $value[$key])),
                    default => trim((string) $value[$key]),
                };

                break;
            }
        }

        return $config;
    }

    /**
     * Nilai rasio 0.0-1.0 (mis. batas markup tautan per chunk).
     */
    private static function ratio(mixed $value): float
    {
        if (!is_numeric($value)) {
            return 0.6;
        }

        return min(1.0, max(0.0, (float) $value));
    }

    /**
     * Normalisasi aturan "content_api" pada config/sites.json.
     *
     * Bentuk yang diterima:
     *   - "content_api": {"rules": [ {...}, {...} ]}  (boleh juga "rules" langsung
     *     berupa daftar, satu objek aturan, atau {"enabled": false})
     *   - "content_api": "https://api.contoh.go.id/halaman/{slug}"  (bentuk
     *     ringkas: satu endpoint yang mengembalikan isi halaman apa adanya)
     *
     * Aturan tanpa "url" diabaikan supaya salah tulis tidak membuat crawl gagal.
     *
     * @return list<array<string, mixed>>
     */
    public static function contentApiRules(mixed $value): array
    {
        return self::apiRules($value, static fn (array $rule): ?array => self::normalizeApiRule($rule));
    }

    /**
     * Normalisasi aturan "document_api" pada config/sites.json: daftar BERKAS
     * DOKUMEN yang hanya bisa ditemukan lewat API JSON situs (tautan unduhan
     * yang baru dibuat JavaScript, mis. halaman /informasi-publik Kemendagri
     * yang daftarnya datang dari backend.kemendagri.go.id).
     *
     * Bentuk yang diterima sama dengan "content_api" (satu objek, daftar,
     * {"rules": [...]}, atau {"enabled": false}); field aturannya:
     *   url (wajib) -> items_field -> children_field -> link_field -> base_url,
     *   ditambah title_field/extensions/max_documents/timeout.
     * Lihat App\Crawl\DocumentApi untuk arti tiap field.
     *
     * @return list<array<string, mixed>>
     */
    public static function documentApiRules(mixed $value): array
    {
        return self::apiRules($value, static fn (array $rule): ?array => self::normalizeDocumentApiRule($rule));
    }

    /**
     * Bentuk KONTANER aturan API yang diterima kedua jenis aturan:
     *   - string (satu endpoint), satu objek aturan, atau daftar aturan
     *   - {"rules": [...]} dan {"enabled": false}
     *
     * @param callable(array<string, mixed>): (?array<string, mixed>) $normalizer
     *
     * @return list<array<string, mixed>>
     */
    private static function apiRules(mixed $value, callable $normalizer): array
    {
        if ($value === null || $value === false || $value === true) {
            return [];
        }

        if (is_string($value)) {
            $url = trim($value);

            return $url === '' ? [] : array_values(array_filter([$normalizer(['url' => $url])]));
        }

        if (!is_array($value)) {
            return [];
        }

        if (array_key_exists('enabled', $value) && !$value['enabled']) {
            return [];
        }

        if (array_key_exists('rules', $value)) {
            $value = $value['rules'];
            if (!is_array($value)) {
                return [];
            }
        }

        // Satu objek aturan (mis. {"url": ..., "field": ...}) dibungkus jadi daftar.
        if (array_key_exists('url', $value) || array_key_exists('endpoint', $value)) {
            $value = [$value];
        }

        $rules = [];
        foreach ($value as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $normalized = $normalizer($rule);
            if ($normalized !== null) {
                $rules[] = $normalized;
            }
        }

        return $rules;
    }

    /**
     * @param array<string, mixed> $rule
     *
     * @return array<string, mixed>|null null bila aturan tidak punya endpoint
     */
    private static function normalizeApiRule(array $rule): ?array
    {
        $url = trim((string) ($rule['url'] ?? $rule['endpoint'] ?? ''));
        if ($url === '') {
            return null;
        }

        return [
            'match' => trim((string) ($rule['match'] ?? $rule['url_pattern'] ?? '')),
            'url' => $url,
            'field' => trim((string) ($rule['field'] ?? $rule['content_field'] ?? '')),
            'title_field' => trim((string) ($rule['title_field'] ?? $rule['titleField'] ?? '')),
            'format' => strtolower(trim((string) ($rule['format'] ?? 'html'))) === 'text' ? 'text' : 'html',
            'min_length' => max(0, (int) ($rule['min_length'] ?? $rule['minLength'] ?? 0)),
            'timeout' => max(0, (int) ($rule['timeout'] ?? 0)),
        ];
    }

    /**
     * Aturan "document_api" ternormalisasi; null bila tidak punya endpoint.
     *
     * @param array<string, mixed> $rule
     *
     * @return array<string, mixed>|null
     */
    private static function normalizeDocumentApiRule(array $rule): ?array
    {
        $url = trim((string) ($rule['url'] ?? $rule['endpoint'] ?? ''));
        if ($url === '') {
            return null;
        }

        return [
            'url' => $url,
            'items_field' => trim((string) ($rule['items_field'] ?? $rule['itemsField'] ?? $rule['field'] ?? '')),
            'children_field' => trim((string) ($rule['children_field'] ?? $rule['childrenField'] ?? 'children')),
            'link_field' => trim((string) ($rule['link_field'] ?? $rule['linkField'] ?? 'file')),
            'title_field' => trim((string) ($rule['title_field'] ?? $rule['titleField'] ?? '')),
            'base_url' => trim((string) ($rule['base_url'] ?? $rule['baseUrl'] ?? '')),
            'extensions' => array_values(array_map(
                static fn ($item): string => strtolower(trim((string) $item)),
                (array) ($rule['extensions'] ?? [])
            )),
            'max_documents' => max(1, (int) ($rule['max_documents'] ?? $rule['maxDocuments'] ?? 200)),
            'timeout' => max(0, (int) ($rule['timeout'] ?? 0)),
        ];
    }

    public function metadataFor(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    public function documentType(): string
    {
        $value = $this->metadataFor('document_type', $this->metadataFor('documentType', 'WEB'));

        return strtoupper((string) $value);
    }

    /**
     * Field "document_type" untuk PAYLOAD vektor.
     *
     * Berbeda dari documentType() yang memakai "WEB" sebagai label tampilan,
     * payload memakai null bila entri tidak menulis documentType/document_type
     * (mis. entri web ringkas atau berkas dokumen tanpa metadata) supaya tipe
     * field sama dengan dokumen lain di collection: "APBD" atau null.
     */
    public function payloadDocumentType(): ?string
    {
        $value = $this->metadataFor('document_type', $this->metadataFor('documentType', ''));

        if (!is_scalar($value)) {
            return null;
        }

        $type = strtoupper(trim((string) $value));

        return $type === '' ? null : $type;
    }

    /**
     * Apakah URL ini berkas dokumen pada entri bertipe dokumen? (dipakai
     * HtmlFetcher untuk mengizinkan respons non-HTML).
     */
    public function isDocument(string $url): bool
    {
        return $this->documentMode && self::isDocumentUrl($url);
    }

    /**
     * Isi field "section" pada payload vektor: "<document_type> <year>"
     * (mis. "APBD 2024"), atau null bila tidak ada metadata yang bisa dipakai.
     *
     * Halaman web tidak punya bagian dokumen, jadi tahun saja BUKAN section
     * (dahulu payload web berisi "section": "2026" yang menyesatkan); null
     * dikembalikan bila documentType kosong atau "WEB".
     */
    public function sectionLabel(): ?string
    {
        $type = $this->documentType();

        if ($type === '' || $type === 'WEB') {
            return null;
        }

        $year = $this->metadataFor('year');
        $parts = array_values(array_filter([
            $type,
            is_scalar($year) ? trim((string) $year) : '',
        ], static fn (string $value): bool => $value !== ''));

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * Normalisasi mode "follow_external": off | family | all. Nilai yang tidak
     * dikenal (kosong/salah tulis) menjadi "off" supaya perilaku tetap aman.
     */
    public static function followExternalMode(mixed $value): string
    {
        $mode = strtolower(trim((string) $value));

        return in_array($mode, ['off', 'family', 'all'], true) ? $mode : 'off';
    }

    /**
     * Ekstensi berkas yang dianggap DOKUMEN (bukan halaman HTML).
     */
    public static function isDocumentUrl(string $url): bool
    {
        $extension = self::urlExtension($url);

        return $extension !== '' && in_array($extension, self::DOCUMENT_EXTENSIONS, true);
    }

    /**
     * URL yang path/kuerinya menandai ENDPOINT UNDUHAN berkas (tanpa ekstensi),
     * mis. "https://ppid.kemendagri.go.id/front/dokumen/download/400470451"
     * atau "https://situs.go.id/unduh?id=12".
     *
     * URL seperti ini diperlakukan sebagai kandidat dokumen supaya permintaannya
     * memakai header/batas ukuran dokumen dan berkasnya disimpan di
     * storage/documents; bila responsnya ternyata HTML, HtmlFetcher tetap
     * mengembalikannya sebagai halaman biasa (lihat HtmlFetcher::toResult()).
     */
    public static function looksLikeDocumentEndpoint(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $kandidat = strtolower((string) ($parts['path'] ?? '') . '?' . (string) ($parts['query'] ?? ''));

        if ($kandidat === '?' || $kandidat === '') {
            return false;
        }

        foreach (self::DOCUMENT_ENDPOINT_KEYWORDS as $keyword) {
            if (str_contains($kandidat, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ekstensi berkas dokumen untuk Content-Type respons, atau null bila
     * Content-Type bukan berkas dokumen (mis. HTML/gambar/arsip).
     */
    public static function documentExtensionForContentType(?string $contentType): ?string
    {
        if ($contentType === null || trim($contentType) === '') {
            return null;
        }

        $mime = strtolower(trim(explode(';', $contentType)[0]));

        return self::DOCUMENT_MIME_EXTENSIONS[$mime] ?? null;
    }

    /**
     * Ekstensi berkas mentah hasil unduhan: dokumen memakai ekstensi aslinya
     * (agar /parse memilih parser yang tepat, mis. parse_pdf.py), halaman lain
     * memakai "html".
     */
    public static function extensionFor(string $url): string
    {
        $extension = self::urlExtension($url);

        return in_array($extension, self::DOCUMENT_EXTENSIONS, true) ? $extension : 'html';
    }

    /**
     * ID site otomatis dari URL. Tanpa $withPath: hanya host
     * (mis. "dindikbud-serangkab-go-id"); dengan $withPath: host + segmen path
     * terakhir (mis. "dindikbud-serangkab-go-id-laporan-anggaran-2024").
     */
    public static function idForUrl(string $url, bool $withPath = false): string
    {
        $id = Text::slug(Text::hostname($url), 60);

        if (!$withPath) {
            return $id;
        }

        $path = trim((string) (parse_url($url, PHP_URL_PATH) ?? ''), '/');
        if ($path === '') {
            return $id;
        }

        $segments = explode('/', $path);

        return Text::slug($id . '-' . (string) end($segments), 60);
    }

    private static function urlExtension(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    /**
     * Label ringkas dari metadata: "APBD Kabupaten Serang 2024" (dipakai
     * sebagai nama site bila entri tidak menulis "name").
     *
     * Tahun saja BUKAN nama: entri web tanpa documentType/city hanya
     * menghasilkan "2026" yang menyesatkan di dashboard, jadi null
     * dikembalikan dan pemanggil memakai host URL sebagai nama.
     *
     * @param array<string, mixed> $metadata
     */
    private static function labelFromMetadata(array $metadata): ?string
    {
        $type = strtoupper((string) ($metadata['document_type'] ?? $metadata['documentType'] ?? ''));
        $city = trim((string) ($metadata['city'] ?? ''));
        $year = $metadata['year'] ?? null;

        if ($type === '' && $city === '') {
            return null;
        }

        $parts = array_values(array_filter([
            $type === 'WEB' ? '' : $type,
            $city,
            is_scalar($year) ? trim((string) $year) : '',
        ], static fn (string $value): bool => $value !== ''));

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * Nama tampilan cadangan dari URL: host tanpa awalan "www."
     * (mis. "kemendagri.go.id") supaya kolom "Nama" dashboard tetap berisi
     * sesuatu yang bisa dibaca, bukan angka tahun atau id slug.
     *
     * @param list<string> $urls
     */
    private static function labelFromUrls(array $urls): ?string
    {
        foreach ($urls as $url) {
            $host = Text::hostname($url);

            if ($host !== '' && $host !== $url) {
                return preg_replace('/^www\./i', '', $host) ?? $host;
            }
        }

        return null;
    }

    /**
     * Host yang diizinkan (diambil dari start_urls).
     *
     * @return list<string>
     */
    public function hosts(): array
    {
        $hosts = [];
        foreach ($this->startUrls as $url) {
            $hosts[] = Text::hostname($url);
        }

        return array_values(array_unique($hosts));
    }

    /**
     * Cek apakah URL boleh di-crawl: host diizinkan, tidak kena
     * exclude_patterns, dan (bila ada) cocok salah satu include_patterns.
     */
    public function allowsUrl(string $url, bool $izinkanHostLain = false): bool
    {
        if (preg_match('#^https?://#i', $url) !== 1) {
            return false;
        }

        if (!$izinkanHostLain && $this->sameHostOnly && !in_array(Text::hostname($url), $this->hosts(), true)) {
            return false;
        }

        foreach ($this->excludePatterns as $pattern) {
            if (@preg_match('#' . $pattern . '#i', $url) === 1) {
                return false;
            }
        }

        if ($this->includePatterns === []) {
            return true;
        }

        foreach ($this->includePatterns as $pattern) {
            if (@preg_match('#' . $pattern . '#i', $url) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = trim($value) === '' ? [] : [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }
}
