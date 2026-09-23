<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\Crawl\ContentApi;
use App\Crawl\DocumentApi;
use App\Crawl\DocumentHarvest;
use App\Crawl\FetchResult;
use App\Crawl\HeadlessRenderer;
use App\Crawl\HtmlFetcher;
use App\Crawl\HtmlToMarkdown;
use App\Crawl\LinkExtractor;
use App\Crawl\RobotsTxt;
use App\Crawl\SiteConfig;
use App\Crawl\SiteRepository;
use App\Http\HttpClient;
use App\Qdrant\QdrantClient;
use App\Qdrant\QdrantJsonExporter;
use App\Qdrant\QdrantPush;
use App\Service\IngestServiceClient;
use App\Support\JobControl;
use App\Support\Logger;
use App\Support\Text;
use Throwable;

/**
 * Orkestrasi lengkap satu run crawler:
 *
 *   ambil robots.txt -> unduh HTML -> POST /parse (fallback lokal) ->
 *   chunk markdown -> POST /embed -> simpan vektor + markdown ->
 *   ekspor berkas JSON siap Qdrant -> unggah ke Qdrant (push otomatis) ->
 *   catat log
 *
 * Setiap tahap mencatat pasangan log SEBELUM/SESUDAH (FETCH_BEFORE/AFTER,
 * PARSE_BEFORE/AFTER, EMBED_BEFORE/AFTER) plus DOC_AFTER per URL.
 */
final class Pipeline
{
    /**
     * Judul teknis yang BUKAN nama dokumen (dipakai looksLikeGenericTitle()):
     * jenis berkas dan label tombol. Nilai seperti ini sering datang dari API
     * halaman (mis. {"type":"file"}) sehingga tanpa daftar ini payload vektor
     * bisa berjudul "file" walaupun dokumennya bernama lain.
     */
    private const GENERIC_TITLES = [
        'file', 'files', 'document', 'documents', 'dokumen', 'berkas', 'lampiran',
        'attachment', 'attachments', 'download', 'downloads', 'unduh', 'unggah',
        'upload', 'uploads', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'csv', 'zip', 'rar', 'image', 'images', 'gambar', 'foto', 'video', 'audio',
        'true', 'false', 'null', 'none', 'ya', 'tidak', 'yes', 'no',
        'untitled', 'tanpa judul', 'no title',
    ];

    private HtmlFetcher $fetcher;

    private IngestServiceClient $service;

    private HttpClient $http;

    private HtmlToMarkdown $fallbackParser;

    /** Pengambil isi halaman dari API situs (aturan "content_api" per site). */
    private ContentApi $contentApi;

    /** Pengumpul berkas dokumen dari API situs (aturan "document_api" per site). */
    private DocumentApi $documentApi;

    /** Penemu berkas dokumen otomatis dari log jaringan browser (NetLog). */
    private DocumentHarvest $harvest;

    /** Batas jumlah berkas & endpoint API penemuan otomatis per halaman. */
    private int $autoDocumentsMax = 200;

    private int $autoDocumentsApis = 8;

    /**
     * URL dokumen hasil "document_api" per site (url => judul). URL seperti ini
     * SELALU diproses sebagai berkas DOKUMEN, boleh berada di host lain dari
     * halaman situs (mis. backend.kemendagri.go.id/uploads/...), dan karena itu
     * tidak lagi diperiksa terhadap host/include/exclude milik site.
     *
     * @var array<string, array<string, string>>
     */
    private array $documentApiUrls = [];

    /**
     * robots.txt per host berkas dokumen (host bisa berbeda dari host situs).
     *
     * @var array<string, RobotsTxt>
     */
    private array $documentRobotsCache = [];

    /** Perender halaman berbasis JavaScript dengan browser headless. */
    private HeadlessRenderer $renderer;

    /** Mode render untuk run yang sedang berjalan (CLI --render menimpa site). */
    private ?string $renderOverride = null;

    /** Batas kemiripan isi antar halaman satu site (1.0 = hanya identik). */
    private float $duplicateSimilarity = 0.85;

    /**
     * crawl.follow_document_links: tautan berkas dokumen (.pdf/.docx/...) yang
     * ditemukan pada halaman web ikut ditelusuri dan diproses dalam MODE
     * DOKUMEN (unduh apa adanya -> /parse memilih parser dari ekstensi).
     */
    private bool $followDocumentLinks = false;

    private VectorStore $store;

    private RunResult $result;

    /** @var array<string, mixed> */
    private array $crawlConfig;

    /** @var array<string, mixed> */
    private array $embedConfig;

    /** @var array<string, mixed> */
    private array $qdrantConfig;

    /** Apakah setiap run yang menghasilkan vektor juga menulis .qdrant.json. */
    private bool $exportJson;

    /** Batas jumlah point untuk satu berkas JSON (0 = tanpa batas). */
    private int $exportMaxPoints;

    /** Apakah setiap run langsung mengunggah vektornya ke Qdrant. */
    private bool $pushAfterCrawl;

    /** Nilai efektif push Qdrant untuk run yang sedang berjalan (CLI menimpa). */
    private bool $pushQdrant = false;

    private bool $localFallbackEnabled;

    /** Apakah /parse pada service tetap dipanggil sebelum parser lokal. */
    private bool $useParseService = true;

    private bool $respectRobotsDefault;

    /** Apakah crawling berantai aktif pada site yang sedang diproses. */
    private bool $followLinksRun = true;

    /** Jumlah URL yang diunduh bersamaan per batch (CRAWL_CONCURRENCY). */
    private int $concurrency = 1;

    /** Batas TOTAL permintaan (unduhan) satu run; 0 = tanpa batas. */
    private int $requestBudget = 0;

    /** Jumlah permintaan yang sudah dipakai pada run yang sedang berjalan. */
    private int $requestsUsed = 0;

    /** @var array<string, RobotsTxt> */
    private array $robotsCache = [];

    /** @var array<string, bool> host yang sudah dicatat melewati verifikasi TLS */
    private array $tlsWarned = [];

    /**
     * Host yang unduhannya berhasil setelah dicoba ulang ke alamat IP lain
     * (tahap ADDR_RETRY); dicatat sekali per host supaya log tidak berulang.
     *
     * @var array<string, bool>
     */
    private array $addressWarned = [];

    /** @var array<string, array<string, string>> sha1 markdown -> URL pertama per site */
    private array $contentSeen = [];

    /**
     * Sidik jari (kata unik) isi halaman yang sudah lolos per site, dipakai
     * untuk menolak halaman yang isinya hampir sama (bukan hanya identik).
     *
     * @var array<string, list<array{url: string, tokens: array<string, bool>}>>
     */
    private array $contentFingerprints = [];

    /**
     * Judul <title> halaman pertama tiap site; dipakai untuk mengenali judul
     * yang sebenarnya judul situs (sama di semua halaman).
     *
     * @var array<string, string>
     */
    private array $siteTitles = [];

    /**
     * @param array<string, mixed> $config seluruh isi config/app.php
     * @param JobControl|null      $control pengendali jeda/lanjutkan dari dashboard
     *                                       (null = run tanpa job, tidak bisa dijeda)
     */
    public function __construct(
        private SiteRepository $sites,
        private Logger $logger,
        private array $config,
        private ?JobControl $control = null,
    ) {
        $this->crawlConfig = $config['crawl'] ?? [];
        $this->embedConfig = $config['embed'] ?? [];
        $qdrantConfig = is_array($config['qdrant'] ?? null) ? $config['qdrant'] : [];
        $this->qdrantConfig = $qdrantConfig;
        $this->exportJson = (bool) ($qdrantConfig['export_json'] ?? true);
        $this->exportMaxPoints = max(0, (int) ($qdrantConfig['export_max_points'] ?? 10000));
        $this->pushAfterCrawl = (bool) ($qdrantConfig['push_after_crawl'] ?? true);
        $this->localFallbackEnabled = (bool) (($config['parse_fallback']['local_markdown'] ?? true));
        $this->useParseService = (bool) (($config['parse_fallback']['try_service'] ?? true));
        $this->respectRobotsDefault = (bool) ($this->crawlConfig['respect_robots'] ?? true);
        $this->concurrency = max(1, (int) ($this->crawlConfig['concurrency'] ?? 1));
        $this->followDocumentLinks = (bool) ($this->crawlConfig['follow_document_links'] ?? false);

        $http = new HttpClient(
            userAgent: (string) ($this->crawlConfig['user_agent'] ?? 'crawler-embed/1.0'),
            connectTimeout: (int) (($config['service']['connect_timeout'] ?? 10)),
            maxRedirects: (int) ($this->crawlConfig['max_redirects'] ?? 5),
            maxBytes: (int) ($this->crawlConfig['max_bytes'] ?? 5_242_880),
            verifyTls: (bool) ($this->crawlConfig['verify_tls'] ?? true),
            insecureRetry: (bool) ($this->crawlConfig['insecure_retry'] ?? true),
            caBundle: (string) ($this->crawlConfig['ca_bundle'] ?? ''),
            // Host yang mengumumkan beberapa alamat A (sebagian tidak menjawab)
            // dicoba ulang ke alamat lain saat tahap koneksi gagal; alamat yang
            // berhasil dipakai untuk sisa run (lihat HttpClient::retryByAddress()).
            addressRetry: (bool) ($this->crawlConfig['retry_addresses'] ?? true),
        );

        $this->http = $http;
        $this->fetcher = new HtmlFetcher($http, $this->crawlConfig);
        $this->service = new IngestServiceClient($http, $config['service'] ?? []);
        $this->fallbackParser = new HtmlToMarkdown(true);
        $this->contentApi = new ContentApi($http, $this->fallbackParser);
        $this->documentApi = new DocumentApi($http);
        $this->harvest = new DocumentHarvest($http);
        $this->autoDocumentsMax = max(1, (int) ($this->crawlConfig['auto_documents_max'] ?? 200));
        $this->autoDocumentsApis = max(0, (int) ($this->crawlConfig['auto_documents_apis'] ?? 8));
        $this->renderer = new HeadlessRenderer($this->crawlConfig);
        $this->duplicateSimilarity = min(1.0, max(0.0, (float) ($this->crawlConfig['duplicate_similarity'] ?? 0.85)));
        $this->store = new VectorStore(
            $config['paths'] ?? [],
            (bool) ($this->embedConfig['store_vectors'] ?? true)
        );
        $this->result = new RunResult();
        $this->result->runId = $this->logger->runId();
    }

    public function result(): RunResult
    {
        return $this->result;
    }

    /**
     * Jalankan crawl untuk daftar site.
     *
     * @param list<SiteConfig>     $sites
     * @param array<string, mixed> $options max_pages, max_depth, follow_links,
     *                                     save_html, respect_robots, max_requests,
     *                                     concurrency, push_qdrant,
     *                                     follow_document_links
     */
    public function run(array $sites, array $options = []): RunResult
    {
        $startedAt = microtime(true);
        $this->result->startedAt = date('Y-m-d H:i:s');
        $this->result->status = 'BERJALAN';

        // Kuota permintaan per run (MAX_REQUESTS_PER_CRAWL) dihitung lintas
        // site; 0 = tanpa batas. Konkurensi hanya berlaku untuk run ini.
        $this->requestBudget = max(0, (int) ($options['max_requests'] ?? $this->crawlConfig['max_requests_per_crawl'] ?? 0));
        $this->requestsUsed = 0;
        $this->concurrency = max(1, (int) ($options['concurrency'] ?? $this->concurrency));

        // Tautan dokumen pada halaman web (crawl.follow_document_links) bisa
        // ditimpa untuk run ini saja oleh pemanggil Pipeline.
        if (array_key_exists('follow_document_links', $options)) {
            $this->followDocumentLinks = (bool) $options['follow_document_links'];
        }

        // Push otomatis ke Qdrant: QDRANT_PUSH_AFTER_CRAWL sebagai bawaan,
        // opsi --push-qdrant/--no-push-qdrant menimpa untuk run ini saja.
        $this->pushQdrant = (bool) ($options['push_qdrant'] ?? $this->pushAfterCrawl);

        // Render halaman dengan browser headless: opsi --render/--no-render
        // menimpa setelan site hanya untuk run ini.
        $this->renderOverride = HeadlessRenderer::modeValue($options['render'] ?? null);

        $this->logger->info('RUN_START', 'Run crawler dimulai', [
            'jumlah_site' => count($sites),
            'service' => $this->service->baseUrl(),
            'parser' => 'POST ' . $this->service->parseUrl(),
            'embedder' => 'POST ' . $this->service->embedUrl(),
            'fallback_lokal' => $this->localFallbackEnabled,
            'verifikasi_tls' => $this->http->verifyTls(),
            'tls_coba_ulang_tanpa_verifikasi' => $this->http->insecureRetry(),
            'timeout_koneksi' => $this->http->connectTimeout() . 's',
            'coba_alamat_lain' => $this->http->addressRetry() ? 'ya' : 'tidak',
            'batas_permintaan_run' => $this->requestBudget === 0 ? 'tanpa batas' : $this->requestBudget,
            'konkurensi_unduh' => $this->concurrency,
            'push_qdrant' => $this->pushQdrant
                ? 'ya -> ' . (string) ($this->qdrantConfig['collection'] ?? 'documents')
                : 'tidak',
            'render_js' => $this->rendererInfo(),
        ]);

        foreach ($sites as $site) {
            if (!$site->enabled) {
                $this->logger->useSite($site);
                $this->logger->event('SITE_SKIP', Logger::STATUS_SKIP, ['site' => $site->id], 'Site dinonaktifkan pada konfigurasi');

                continue;
            }

            if ($site->startUrls === []) {
                $this->logger->useSite($site);
                $this->logger->event('SITE_SKIP', Logger::STATUS_SKIP, ['site' => $site->id], 'Site tidak punya start_urls');

                continue;
            }

            // Jeda job dari dashboard juga berlaku antar site.
            $this->pauseCheckpoint('antar-site', $site->id);

            $this->crawlSite($site, $options);

            if ($this->requestBudget > 0 && $this->requestsUsed >= $this->requestBudget) {
                $this->logger->event('LIMIT_RUN', Logger::STATUS_SKIP, [
                    'batas_permintaan' => $this->requestBudget,
                    'permintaan_terpakai' => $this->requestsUsed,
                ], 'Kuota permintaan satu run sudah habis, site berikutnya tidak di-crawl');

                break;
            }
        }

        $this->result->durationSeconds = microtime(true) - $startedAt;
        $this->result->finishedAt = date('Y-m-d H:i:s');
        $this->result->status = $this->result->fetchFailed === 0 && $this->result->parseFailed === 0
            ? 'SUKSES'
            : ($this->result->urlsFetched > 0 ? 'SUKSES_SEBAGIAN' : 'GAGAL');

        $this->logger->clearUrl();
        $this->logger->info('RUN_END', 'Run crawler selesai', [
            'durasi' => Text::duration($this->result->durationSeconds),
            'status' => $this->result->status,
        ]);
        $this->logger->summary('RUN ' . $this->result->runId, $this->result->summaryRows());
        $this->logger->writeLatestPointer($this->result->status, [
            'jumlah_site' => count($this->result->sites),
            'dokumen' => $this->result->documents,
            'chunk' => $this->result->chunks,
            'vektor' => $this->result->embedded,
        ]);

        return $this->result;
    }

    /**
     * Crawl satu site secara berantai (BFS) dengan pembatasan halaman,
     * kedalaman, jeda antar request, dan penghormatan robots.txt.
     *
     * @param array<string, mixed> $options
     */
    private function crawlSite(SiteConfig $site, array $options): void
    {
        $siteStartedAt = microtime(true);
        $id = $site->id;

        $this->logger->useSite($site);
        $this->logger->clearUrl();

        // 0 = TANPA BATAS (bawaan): seluruh halaman/berkas yang ditemukan
        // diproses sampai antrean habis. Batas antrean internal pun dilepas
        // supaya halaman daftar/paginasi tidak memotong penemuan dokumen.
        $maxPages = max(0, (int) ($options['max_pages'] ?? $site->maxPages));
        $batasHalaman = $maxPages === 0 ? PHP_INT_MAX : $maxPages;
        $batasAntrean = $maxPages === 0 ? PHP_INT_MAX : $maxPages * 5;
        $maxDepth = max(0, (int) ($options['max_depth'] ?? $site->maxDepth));
        $followLinks = (bool) ($options['follow_links'] ?? $site->followLinks);
        $saveHtml = (bool) ($options['save_html'] ?? $site->saveHtml);
        $this->followLinksRun = $followLinks;

        $stats = &$this->result->site($id);
        $stats['nama'] = $site->name;

        // Sidik isi (sha1 markdown) dihitung ulang per site: URL berbeda yang
        // menghasilkan markdown identik hampir selalu boilerplate situs.
        $this->contentSeen[$id] = [];

        $this->logger->info('SITE_START', 'Mulai crawl site', [
            'nama' => $site->name,
            'jenis' => $site->documentMode ? 'dokumen' : 'web',
            'start_urls' => implode(', ', $site->startUrls),
            'max_halaman' => $maxPages === 0 ? 'tanpa batas' : $maxPages,
            'max_kedalaman' => $maxDepth,
            'ikuti_tautan' => $followLinks,
            'tautan_dokumen' => $this->followDocumentLinks ? 'diikuti' : 'dilewati',
            'chunk' => $site->chunkSize . '/' . $site->chunkOverlap,
            'aturan_api_konten' => $site->contentApi === [] ? 'tidak ada' : count($site->contentApi) . ' aturan',
            'berkas_api_dokumen' => $site->documentApi === [] ? 'tidak ada' : count($site->documentApi) . ' aturan',
            'konten_duplikat' => $site->dropDuplicateContent ? 'dibuang' : 'disimpan',
            'batas_permintaan_run' => $this->requestBudget === 0 ? 'tanpa batas' : $this->requestBudget,
            'kuota_tersisa' => $this->requestBudget === 0 ? 'tanpa batas' : max(0, $this->requestBudget - $this->requestsUsed),
            'konkurensi_unduh' => $this->concurrency,
        ]);

        $respectRobots = (bool) ($options['respect_robots'] ?? ($site->respectRobots && $this->respectRobotsDefault));
        $robots = $this->robotsFor($site, $respectRobots);
        $this->logger->event(
            'ROBOTS',
            $robots->found ? Logger::STATUS_OK : Logger::STATUS_SKIP,
            ['robots' => $robots->url, 'aturan' => $robots->ruleCount()]
                + ($robots->remoteIp !== '' ? ['ip' => $robots->remoteIp] : []),
            $robots->summary()
        );

        // robots.txt bisa menjadi permintaan PERTAMA ke host site ini: bila
        // unduhnya berhasil setelah berganti alamat IP, catat tahap ADDR_RETRY
        // di sini supaya sebabnya tetap terlihat walaupun unduhan halaman
        // berikutnya sudah memakai alamat yang benar dan tampak cepat.
        if ($robots->addressRetry) {
            $this->warnAddressRetry($robots->url, $robots->remoteIp);
        }

        $delayMs = $robots->effectiveDelayMs($site->rateLimitMs);
        $vectorFile = $this->store->vectorPath($site, $this->logger->runId());
        $vectorsWritten = 0;
        $documents = [];

        /**
         * Berkas DOKUMEN mentah (mis. PDF) yang vektornya sudah jadi; dihapus
         * setelah site ini dikirim ke Qdrant supaya storage/documents tidak
         * menumpuk (lihat pruneDocuments()).
         *
         * @var array<string, bool> path absolut => true
         */
        $dokumenUntukDihapus = [];
        $processed = 0;
        $isFirstRequest = true;

        $queue = [];
        $visited = [];
        foreach ($site->startUrls as $startUrl) {
            $queue[] = ['url' => $startUrl, 'depth' => 0];
            $visited[$startUrl] = true;
            $this->result->urlsQueued++;
        }

        // Berkas dokumen dari API situs (aturan "document_api") didaftarkan
        // SESUDAH start_urls: tautan unduhannya tidak pernah ada di HTML/DOM
        // (dibuat JavaScript) dan berkasnya sering disajikan host lain (mis.
        // backend.kemendagri.go.id/uploads/...). URL-nya ikut dihitung pada
        // kuota max_pages / max_requests seperti URL lain.
        // Aturan ini milik config (bukan pemrograman parser JSON pihak ketiga),
        // tetapi kegagalannya tetap tidak boleh mematikan seluruh run.
        try {
            $this->queueDocumentApi($site, $queue, $visited, $respectRobots, $stats);
        } catch (Throwable $exception) {
            $this->logger->event('DOC_API', Logger::STATUS_FAIL, [
                'galat' => Text::oneLine($exception->getMessage(), 200),
            ], 'Aturan document_api gagal dijalankan; crawl dilanjutkan tanpa daftar berkas dari API situs');
        }

        while ($queue !== []) {
            $sisaHalaman = $batasHalaman - $processed;

            if ($sisaHalaman <= 0) {
                $this->limitReached($queue, $maxPages, 'max_pages', $stats);
                break;
            }

            // Kuota permintaan satu run dihitung lintas site, jadi site kedua
            // bisa kehabisan jatah walau batas halaman site belum tercapai.
            $kuotaRun = $this->requestBudget === 0
                ? PHP_INT_MAX
                : ($this->requestBudget - $this->requestsUsed);

            if ($kuotaRun <= 0) {
                $this->limitReached($queue, $this->requestBudget, 'max_requests_per_crawl', $stats);
                break;
            }

            // Kumpulkan URL siap proses sebanyak jendela konkurensi. URL yang
            // dilarang host/include/exclude atau robots.txt tetap dilewati
            // seketika dan tidak ikut masuk batch.
            $window = max(1, min($this->concurrency, $sisaHalaman, $kuotaRun));
            $batch = [];

            while ($queue !== [] && count($batch) < $window) {
                $item = array_shift($queue);
                $url = (string) $item['url'];
                $depth = (int) $item['depth'];

                $this->logger->useUrl($url);
                $dariApiDokumen = isset($this->documentApiUrls[$site->id][$url]);

                // URL hasil "document_api" sudah ditentukan pemilik site lewat
                // API resminya dan berkasnya sering ada di host lain, jadi
                // pemeriksaan host/include/exclude site dilewati (robots.txt
                // host berkas tetap diperiksa saat pendaftaran antrean).
                if (!$dariApiDokumen && !$site->allowsUrl($url)) {
                    $this->result->urlsSkipped++;
                    $stats['url_dilewati']++;
                    $this->logger->event('LINK_SKIP', Logger::STATUS_SKIP, ['url' => $url, 'kedalaman' => $depth], 'Tidak cocok host/include/exclude site');

                    continue;
                }

                if (!$dariApiDokumen && !$robots->allows($url)) {
                    $this->result->urlsSkipped++;
                    $stats['url_dilewati']++;
                    $this->logger->event('ROBOTS_BLOCK', Logger::STATUS_SKIP, ['url' => $url], 'Dilarang oleh robots.txt');

                    continue;
                }

                $batch[] = ['url' => $url, 'depth' => $depth];
            }

            if ($batch === []) {
                continue;
            }

            // Titik aman untuk JEDA job dari dashboard: batch halaman sebelumnya
            // (termasuk /parse dan /embed-nya) sudah selesai sehingga tidak ada
            // unduhan yang terpotong di tengah. Sisa antrean tetap utuh dan
            // diteruskan setelah perintah lanjutkan.
            $this->pauseCheckpoint('antar-batch-halaman', $id);

            // Jeda berlaku antar BATCH (bukan antar URL) supaya konkurensi
            // tidak melanggar rate_limit_ms site/robots.txt.
            if (!$isFirstRequest && $delayMs > 0) {
                usleep($delayMs * 1000);
            }
            $isFirstRequest = false;

            $preFetched = $this->fetchBatch($site, $batch);

            foreach ($batch as $item) {
                $url = (string) $item['url'];
                $depth = (int) $item['depth'];

                $this->logger->useUrl($url);
                $outcome = $this->processUrl($site, $url, $vectorFile, $depth, $maxDepth, $saveHtml, $preFetched[$url] ?? null);
                $processed++;
                $this->requestsUsed++;

                if ($outcome['document'] !== null) {
                    $documents[] = $outcome['document'];
                }

                // Hanya dokumen yang SUDAH menghasilkan vektor yang dibuang
                // berkas mentahnya: dokumen yang gagal diparse/di-embed tetap
                // disimpan supaya masih bisa diproses ulang.
                if ($outcome['document_path'] !== '' && $outcome['vectors'] > 0) {
                    $dokumenUntukDihapus[$outcome['document_path']] = true;
                }

                $vectorsWritten += $outcome['vectors'];

                if (!$outcome['ok'] || !$followLinks || $depth >= $maxDepth) {
                    continue;
                }

                $queued = 0;
                $dokumenBaru = [];
                $halamanBaru = [];

                // Berkas dokumen hasil penemuan otomatis dari halaman ini (log
                // jaringan browser): diperlakukan seperti tautan dokumen lain --
                // disisipkan di DEPAN antrean supaya segera diunduh -> /parse ->
                // /embed -> Qdrant.
                foreach ($outcome['documents_auto'] as $docUrl => $judul) {
                    if (isset($visited[$docUrl])) {
                        continue;
                    }

                    $visited[$docUrl] = true;
                    $this->result->urlsQueued++;
                    $queued++;
                    $dokumenBaru[] = ['url' => $docUrl, 'depth' => $depth + 1];
                }

                foreach ($outcome['links'] as $link) {
                    if (isset($visited[$link])) {
                        continue;
                    }

                    // Tautan dokumen (PDF/XLSX/... atau endpoint unduhan) diberi
                    // PRIORITAS: isinya justru yang dicari, sedangkan halaman
                    // daftar/paginasi bisa menghabiskan kuota max_pages dan
                    // max_requests_per_crawl sebelum PDF pada halaman detail
                    // sempat diunduh. Batas antrean (max_pages * 5, dilepas bila
                    // max_pages = 0 / tanpa batas) karena itu tidak berlaku untuk
                    // tautan dokumen.
                    $linkDokumen = $this->isDocumentUrl($site, $link);

                    if (!$linkDokumen && count($queue) + count($halamanBaru) >= $batasAntrean) {
                        continue;
                    }

                    $visited[$link] = true;
                    $this->result->urlsQueued++;
                    $queued++;

                    if ($linkDokumen) {
                        $dokumenBaru[] = ['url' => $link, 'depth' => $depth + 1];

                        continue;
                    }

                    $halamanBaru[] = ['url' => $link, 'depth' => $depth + 1];
                }

                // Dokumen disisipkan di DEPAN antrean supaya diunduh pada batch
                // berikutnya, bukan menunggu ratusan halaman lain lebih dahulu.
                if ($dokumenBaru !== []) {
                    array_splice($queue, 0, 0, $dokumenBaru);
                }

                foreach ($halamanBaru as $item) {
                    $queue[] = $item;
                }

                if ($queued > 0) {
                    $this->logger->info('LINK_QUEUE', $dokumenBaru === []
                        ? 'Tautan baru masuk antrean'
                        : 'Tautan baru masuk antrean (dokumen diprioritaskan di depan)', [
                            'baru' => $queued,
                            'dokumen' => count($dokumenBaru),
                            'antrean' => count($queue),
                            'kedalaman_berikut' => $depth + 1,
                        ]);
                }
            }
        }

        $duration = microtime(true) - $siteStartedAt;
        $this->result->addDuration($id, $duration);

        if ($vectorsWritten > 0) {
            $finish = $this->finishSite($site, $vectorFile, $vectorsWritten, $documents);
            $qdrantFile = $finish['berkas_qdrant'];
            $qdrantPush = $finish['qdrant_push'];
        }

        // Berkas DOKUMEN mentah (mis. PDF) dihapus setelah vektornya terkirim
        // ke Qdrant supaya storage/documents tidak menumpuk. Markdown, berkas
        // vektor, manifest, dan isi point Qdrant tetap tersimpan sehingga isi
        // dokumen masih bisa dicari/dikirim ulang.
        if ($dokumenUntukDihapus !== []) {
            if (!$site->deleteDocumentsAfterPush) {
                $this->logger->event('DOC_CLEANUP', Logger::STATUS_SKIP, [
                    'berkas' => count($dokumenUntukDihapus),
                ], 'Berkas dokumen mentah disimpan (CRAWLER_DELETE_DOCUMENTS_AFTER_PUSH=false / '
                    . '"delete_documents_after_push": false pada entri site)');
            } elseif ($qdrantPush === null) {
                $this->logger->event('DOC_CLEANUP', Logger::STATUS_SKIP, [
                    'berkas' => count($dokumenUntukDihapus),
                ], 'Berkas dokumen mentah disimpan: push Qdrant tidak aktif pada run ini (pakai --push-qdrant/'
                    . 'QDRANT_PUSH_AFTER_CRAWL=true supaya berkas dihapus setelah terkirim)');
            } else {
                $this->pruneDocuments($dokumenUntukDihapus);
            }
        }

        $stat = $this->result->sites[$id];
        $ringkasanSite = [
            'ID site' => $site->id,
            'Durasi' => Text::durationMinutes($duration) . ' (' . Text::duration($duration) . ')',
            'URL diproses' => $stat['url_diproses'],
            'URL dilewati' => $stat['url_dilewati'],
            'Gagal unduh' => $stat['gagal_unduh'],
            'Parse /parse' => $stat['parse_service'],
            'Parse fallback' => $stat['parse_fallback'],
            'Dokumen' => $stat['dokumen'],
            'Chunk' => $stat['chunk'],
            'Vektor' => $stat['vektor'],
            'Berkas vektor' => $vectorsWritten > 0 ? $this->store->relative($vectorFile) : '-',
            'Berkas Qdrant' => $vectorsWritten > 0 && $qdrantFile !== ''
                ? $this->store->relative($qdrantFile)
                : ($vectorsWritten > 0 && $this->exportJson ? 'gagal/dilewati (lihat QDRANT_EXPORT)' : '-'),
            'Push otomatis Qdrant' => $vectorsWritten > 0 && $qdrantPush !== null
                ? $qdrantPush['status'] . ' (' . $qdrantPush['collection'] . ': terkirim '
                    . $qdrantPush['point_terkirim'] . ', gagal ' . $qdrantPush['point_gagal'] . ')'
                : ($this->pushQdrant ? '-' : 'tidak aktif'),
        ];

        // Berkas dokumen mentah yang masih tertinggal (dulu gagal diparse saat
        // /parse mati, atau tidak sempat diproses karena batas max_pages) bisa
        // dibaca ulang TANPA crawl ulang lewat perintah di bawah. Tanpa
        // pemberitahuan ini, PDF yang tidak masuk Qdrant tidak terlihat sebab
        // berkasnya hanya "diam" di storage/documents.
        $tertunda = (new PendingDocuments($this->store))->count($site);

        if ($tertunda > 0) {
            $perintah = 'php bin/crawl.php documents --site=' . $site->id;

            $ringkasanSite['Dokumen tertunda'] = $tertunda . ' berkas di storage/documents/' . $site->id
                . ' (belum di-embed) -> ' . $perintah;

            $this->logger->event('DOC_TERTUNDA', Logger::STATUS_SKIP, [
                'site' => $site->id,
                'berkas' => $tertunda,
                'perintah' => $perintah,
            ], 'Masih ada berkas dokumen mentah yang belum di-embed; proses ulang tanpa crawl: ' . $perintah);
        }

        $this->logger->summary('Site ' . $site->name, $ringkasanSite);

        $this->logger->info('SITE_END', 'Crawl site selesai', [
            'site' => $site->id,
            'durasi' => Text::duration($duration),
            'vektor' => $stat['vektor'],
        ]);
    }

    /**
     * Hapus berkas DOKUMEN mentah (mis. PDF) yang vektornya sudah terkirim ke
     * Qdrant.
     *
     * Dipakai supaya unduhan berkas tidak menumpuk di storage/documents: isi
     * dokumen tetap bisa dicari karena markdown, payload Qdrant, dan berkas
     * vektor tidak dihapus. Berkas yang gagal diparse/di-embed TIDAK masuk daftar
     * ini (lihat pemanggil) supaya masih bisa diproses ulang.
     *
     * @param array<string, bool> $paths path absolut => true
     */
    private function pruneDocuments(array $paths): void
    {
        $terhapus = 0;
        $gagal = 0;
        $byte = 0;

        foreach (array_keys($paths) as $path) {
            // Berkas bisa sudah tidak ada (mis. sudah dibersihkan manual):
            // bukan kegagalan, cukup dilewati.
            if (!is_file($path)) {
                continue;
            }

            $ukuran = (int) @filesize($path);

            if (@unlink($path)) {
                $terhapus++;
                $byte += max(0, $ukuran);

                continue;
            }

            $gagal++;
        }

        if ($terhapus === 0 && $gagal === 0) {
            $this->logger->event('DOC_CLEANUP', Logger::STATUS_SKIP, [
                'berkas' => count($paths),
            ], 'Berkas dokumen mentah tidak ada lagi di storage (sudah dibersihkan sebelumnya)');

            return;
        }

        $this->logger->event('DOC_CLEANUP', $gagal === 0 ? Logger::STATUS_OK : Logger::STATUS_SKIP, [
            'dihapus' => $terhapus,
            'gagal' => $gagal,
            'ukuran' => Text::humanBytes($byte),
        ], 'Berkas dokumen mentah dihapus setelah terkirim ke Qdrant (markdown + isi vektor tetap tersimpan)');
    }

    /**
     * Ekspor berkas .qdrant.json + push otomatis ke Qdrant + tulis manifest
     * untuk satu site.
     *
     * Dipakai crawl biasa (crawlSite) DAN pemrosesan ulang dokumen
     * (`php bin/crawl.php documents`) supaya keduanya menghasilkan berkas
     * pendamping, log, dan aturan push yang sama.
     *
     * @param list<array<string, mixed>> $documents detail dokumen yang menghasilkan vektor
     *
     * @return array{berkas_qdrant: string, qdrant_push: array<string, mixed>|null}
     */
    private function finishSite(SiteConfig $site, string $vectorFile, int $vectorsWritten, array $documents): array
    {
        $qdrantFile = '';
        $qdrantStats = null;
        $qdrantProblem = '';

        if ($this->exportJson) {
            $qdrantFile = $this->store->qdrantPath($site, $this->logger->runId());

            try {
                $qdrantStats = (new QdrantJsonExporter(
                    $vectorFile,
                    (string) ($this->qdrantConfig['collection'] ?? 'documents'),
                    true,
                ))->export($qdrantFile, $this->exportMaxPoints);

                $this->logger->event('QDRANT_EXPORT', Logger::STATUS_OK, [
                    'berkas' => $this->store->relative($qdrantFile),
                    'point' => $qdrantStats['point'],
                    'dimensi' => $qdrantStats['dimensi'],
                    'ukuran' => Text::humanBytes((int) $qdrantStats['ukuran']),
                    'baris_bermasalah' => count($qdrantStats['problem']),
                    'durasi' => Text::duration((float) $qdrantStats['durasi']),
                ], 'Berkas JSON siap kirim ke Qdrant dibuat (satu berkas = sekali unggah)');
            } catch (\Throwable $exception) {
                // Ekspor JSON tidak boleh menggagalkan crawl yang sudah sukses.
                $qdrantProblem = $exception->getMessage();
                $qdrantFile = '';

                $this->logger->event('QDRANT_EXPORT', Logger::STATUS_SKIP, [
                    'berkas' => $this->store->relative($vectorFile),
                ], 'Ekspor JSON dilewati: ' . $qdrantProblem);
            }
        }

        // Push otomatis ke Qdrant: berkas .jsonl site ini sudah final
        // (tahap /embed selesai), jadi diunggah sekarang tanpa menunggu
        // site lain. Kegagalan unggah dicatat (QDRANT_PUSH) dan TIDAK
        // menggagalkan crawl; berkas tetap ada untuk diulang manual.
        $qdrantPush = $this->pushQdrant ? $this->pushToQdrant($vectorFile, $site) : null;

        $manifest = [
            'run_id' => $this->logger->runId(),
            'site_id' => $site->id,
            'site_name' => $site->name,
            'dibuat' => date('Y-m-d H:i:s'),
            'file_vektor' => $this->store->relative($vectorFile),
            'dokumen' => count($documents),
            'vektor' => $vectorsWritten,
            'dimensi' => (int) ($this->embedConfig['expected_dimension'] ?? 1024),
            'embedder' => $this->service->embedUrl(),
            'dokumen_detail' => $documents,
        ];

        if ($qdrantStats !== null) {
            $manifest['file_qdrant'] = $this->store->relative($qdrantFile);
            $manifest['vektor_json'] = $qdrantStats['point'];
        } elseif ($qdrantProblem !== '') {
            $manifest['file_qdrant'] = null;
            $manifest['catatan_json'] = $qdrantProblem;
        }

        if ($qdrantPush !== null) {
            $manifest['qdrant_push'] = [
                'collection' => $qdrantPush['collection'],
                'url' => $qdrantPush['url_qdrant'],
                'status' => $qdrantPush['status'],
                'dimensi' => $qdrantPush['dimensi'],
                'point_terkirim' => $qdrantPush['point_terkirim'],
                'point_gagal' => $qdrantPush['point_gagal'],
                'collection_dibuat' => $qdrantPush['collection_dibuat'],
                'catatan' => $qdrantPush['catatan'],
            ];
        }

        $this->store->writeManifest($this->store->manifestPath($site, $this->logger->runId()), $manifest);

        return ['berkas_qdrant' => $qdrantFile, 'qdrant_push' => $qdrantPush];
    }

    /**
     * Proses ULANG berkas DOKUMEN yang SUDAH ada di storage/documents TANPA
     * mengunduh lagi: /parse -> markdown -> chunk -> /embed -> berkas vektor
     * -> Qdrant.
     *
     * Dipakai perintah `php bin/crawl.php documents`. Berkas dokumen yang gagal
     * diparse/di-embed sengaja TIDAK dihapus setelah crawl (lihat crawlSite)
     * supaya bisa diproses lagi setelah penyebabnya beres -- kasus nyata:
     * layanan /parse sedang mati (semua PDF dilewati) atau run berhenti karena
     * batas max_pages sebelum PDF pada halaman detail sempat diunduh. Dengan
     * perintah ini PDF tersebut langsung dibaca dan di-embed ke Qdrant tanpa
     * crawl ulang; document_id/url payload tetap sama karena dihitung dari URL.
     *
     * @param list<array{url: string, path: string}> $items
     * @param array<string, mixed>                   $options push_qdrant
     *
     * @return array<string, mixed> ringkasan hasil
     */
    public function resumeDocuments(SiteConfig $site, array $items, array $options = []): array
    {
        $id = $site->id;
        $startedAt = microtime(true);
        $stats = &$this->result->site($id);

        $this->pushQdrant = (bool) ($options['push_qdrant'] ?? $this->pushAfterCrawl);
        // Dokumen tidak punya halaman HTML/tautan untuk ditelusuri. Daftar
        // tautan dokumen (follow_document_links) TIDAK dimatikan supaya URL
        // endpoint unduhan tanpa ekstensi tetap dikenali sebagai dokumen pada
        // log (jenis=dokumen_tautan) seperti hasil crawl biasa.
        $this->followLinksRun = false;
        $this->contentSeen[$id] = [];

        $this->logger->useSite($site);
        $this->logger->clearUrl();

        $vectorFile = $this->store->vectorPath($site, $this->logger->runId());
        $vectorsWritten = 0;
        $documents = [];
        $dokumenUntukDihapus = [];
        $diproses = 0;
        $gagal = 0;
        $hilang = 0;

        $this->logger->info('DOC_RESUME', 'Memproses ulang berkas dokumen yang sudah ada di storage/documents', [
            'site' => $id,
            'berkas' => count($items),
            'push_qdrant' => $this->pushQdrant
                ? 'ya -> ' . (string) ($this->qdrantConfig['collection'] ?? 'documents')
                : 'tidak',
        ]);

        foreach ($items as $item) {
            $url = (string) ($item['url'] ?? '');
            $path = (string) ($item['path'] ?? '');

            if ($url === '' || $path === '') {
                continue;
            }

            $this->logger->useUrl($url);

            if (!is_file($path)) {
                $hilang++;
                $this->logger->event('DOC_RESUME_SKIP', Logger::STATUS_SKIP, [
                    'berkas' => $this->store->relative($path),
                ], 'Berkas dokumen tidak ada lagi di storage; dilewati');

                continue;
            }

            $outcome = $this->processUrl(
                $site,
                $url,
                $vectorFile,
                0,
                0,
                false,
                self::localDocumentFetch($url, $path),
                'berkas lokal',
            );

            $diproses++;
            $vectorsWritten += $outcome['vectors'];

            if ($outcome['document'] !== null) {
                $documents[] = $outcome['document'];
            }

            if ($outcome['vectors'] > 0) {
                if ($outcome['document_path'] !== '') {
                    $dokumenUntukDihapus[$outcome['document_path']] = true;
                }

                continue;
            }

            $gagal++;
        }

        $finish = ['berkas_qdrant' => '', 'qdrant_push' => null];

        if ($vectorsWritten > 0) {
            $finish = $this->finishSite($site, $vectorFile, $vectorsWritten, $documents);
        }

        // Berkas mentah hanya dihapus bila vektornya benar-benar terkirim ke
        // Qdrant -- aturan yang sama dipakai crawl biasa.
        if ($dokumenUntukDihapus !== []) {
            if (!$site->deleteDocumentsAfterPush) {
                $this->logger->event('DOC_CLEANUP', Logger::STATUS_SKIP, [
                    'berkas' => count($dokumenUntukDihapus),
                ], 'Berkas dokumen mentah disimpan (CRAWLER_DELETE_DOCUMENTS_AFTER_PUSH=false / '
                    . '"delete_documents_after_push": false pada entri site)');
            } elseif ($finish['qdrant_push'] === null) {
                $this->logger->event('DOC_CLEANUP', Logger::STATUS_SKIP, [
                    'berkas' => count($dokumenUntukDihapus),
                ], 'Berkas dokumen mentah disimpan: push Qdrant tidak aktif pada run ini (pakai --push-qdrant)');
            } else {
                $this->pruneDocuments($dokumenUntukDihapus);
            }
        }

        $duration = microtime(true) - $startedAt;
        $this->result->addDuration($id, $duration);

        $this->logger->info('DOC_RESUME_END', 'Pemrosesan ulang berkas dokumen selesai', [
            'site' => $id,
            'diproses' => $diproses,
            'dokumen' => count($documents),
            'chunk' => (int) $stats['chunk'],
            'vektor' => $vectorsWritten,
            'gagal' => $gagal,
            'durasi' => Text::duration($duration),
        ]);

        return [
            'site' => $id,
            'diproses' => $diproses,
            'gagal' => $gagal,
            'hilang' => $hilang,
            'dokumen' => count($documents),
            'vektor' => $vectorsWritten,
            'berkas_vektor' => $vectorsWritten > 0 ? $this->store->relative($vectorFile) : '',
            'berkas_qdrant' => $finish['berkas_qdrant'],
            'qdrant_push' => $finish['qdrant_push'],
            'dokumen_detail' => $documents,
            'durasi' => $duration,
        ];
    }

    /**
     * FetchResult untuk berkas dokumen yang SUDAH ada di disk.
     *
     * Dipakai pemrosesan ulang dokumen supaya tahap promote -> /parse -> chunk
     * -> /embed berjalan persis seperti hasil unduhan biasa, hanya tanpa
     * mengunduh ulang berkasnya. Content-Type ditentukan dari ekstensi berkas
     * (dipakai promoteDocument dan pemilihan parser pada layanan /parse).
     */
    private static function localDocumentFetch(string $url, string $path): FetchResult
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = array_search($extension, SiteConfig::DOCUMENT_MIME_EXTENSIONS, true);

        return new FetchResult(
            url: $url,
            effectiveUrl: $url,
            httpStatus: 200,
            ok: true,
            isHtml: false,
            contentType: is_string($mime) ? $mime : 'application/octet-stream',
            bytes: max(0, (int) @filesize($path)),
            durationSeconds: 0.0,
            path: $path,
            charset: null,
            error: null,
        );
    }

    /**
     * Unduh seluruh URL satu batch: paralel bila konkurensi > 1.
     *
     * @param list<array{url: string, depth: int}> $batch
     *
     * @return array<string, FetchResult> hasil unduhan per URL
     */
    private function fetchBatch(SiteConfig $site, array $batch): array
    {
        $targets = [];
        $documents = [];
        foreach ($batch as $item) {
            $url = (string) $item['url'];
            $targets[$url] = $this->store->rawPath($site, $url);
            // Keputusan dokumen dihitung sekali di sini supaya unduhan batch
            // memakai header/batas ukuran yang tepat (lihat isDocumentUrl()).
            $documents[$url] = $this->isDocumentUrl($site, $url);
        }

        if ($this->concurrency > 1 && count($targets) > 1) {
            $this->logger->info('FETCH_BATCH', 'Mengunduh ' . count($targets) . ' halaman sekaligus', [
                'konkurensi' => $this->concurrency,
            ]);

            return $this->fetcher->fetchMany($site, $targets, $this->concurrency, $documents);
        }

        $results = [];
        foreach ($targets as $url => $path) {
            $results[$url] = $this->fetcher->fetch($site, $url, $path, $documents[$url] ?? null);
        }

        return $results;
    }

    /**
     * Catat bahwa crawl site dihentikan karena batas halaman/permintaan run.
     *
     * @param list<array{url: string, depth: int}> $queue
     * @param int                                  $batas nilai batas yang tercapai
     * @param array<string, mixed>                 $stats
     */
    private function limitReached(array $queue, int $batas, string $sebab, array &$stats): void
    {
        $sisa = count($queue);

        $this->logger->event('LIMIT', Logger::STATUS_SKIP, [
            'batas' => $batas,
            'sebab' => $sebab,
            'batas_permintaan_run' => $this->requestBudget,
            'permintaan_terpakai' => $this->requestsUsed,
        ], 'Batas crawl tercapai (' . $sebab . '), sisa antrean ' . $sisa . ' URL tidak diproses');

        $this->result->urlsSkipped += $sisa;
        $stats['url_dilewati'] += $sisa;
    }

    /**
     * Apakah $url harus diproses sebagai BERKAS DOKUMEN (mode dokumen)?
     *
     * Ya bila salah satu benar:
     *   a) entri config/sites.json-nya bertipe dokumen (SiteConfig::isDocument),
     *   b) URL-nya berakhiran ekstensi dokumen (pdf/docx/xlsx/...) dan
     *      crawl.follow_document_links aktif, sehingga tautan dokumen yang
     *      ditemukan pada halaman web ikut ditelusuri,
     *   c) URL-nya endpoint unduhan tanpa ekstensi (mis.
     *      "/front/dokumen/download/400470451", "/unduh?id=12") dan
     *      crawl.follow_document_links aktif. Respons HTML pada URL seperti itu
     *      tetap diperlakukan sebagai halaman biasa (HtmlFetcher mengganti
     *      ekstensinya menjadi .html).
     *
     * URL dokumen TIDAK dirender JavaScript dan tidak diparse parser lokal:
     * berkasnya diunduh apa adanya lalu dikirim ke /parse agar parser dipilih
     * dari ekstensi berkas (mis. parse_pdf.py untuk .pdf).
     */
    private function isDocumentUrl(SiteConfig $site, string $url): bool
    {
        // URL dari API dokumen situs (aturan "document_api") SELALU dokumen:
        // berkasnya sering berada di host lain (mis. backend.kemendagri.go.id)
        // dan ekstensinya bisa saja tanpa .pdf (endpoint unduhan).
        if (isset($this->documentApiUrls[$site->id][$url])) {
            return true;
        }

        if ($site->isDocument($url)) {
            return true;
        }

        if (!$this->followDocumentLinks) {
            return false;
        }

        return SiteConfig::isDocumentUrl($url) || SiteConfig::looksLikeDocumentEndpoint($url);
    }

    /**
     * Pindahkan berkas hasil unduhan ke storage/documents bila URL-nya baru
     * diketahui sebagai berkas dokumen setelah diunduh (mis. endpoint unduhan
     * "/front/dokumen/download/12" atau "/unduh?id=7").
     *
     * Ekstensi diambil dari nama berkas hasil HtmlFetcher; bila penggantian nama
     * di sana gagal, Content-Type yang dipakai. Mengembalikan path yang dipakai
     * tahap berikutnya (path asal bila pemindahan gagal).
     */
    private function promoteDocument(SiteConfig $site, string $url, FetchResult $fetch, string $rawPath): string
    {
        $extension = strtolower(pathinfo($rawPath, PATHINFO_EXTENSION));

        if ($extension === '' || $extension === 'html') {
            $extension = SiteConfig::documentExtensionForContentType($fetch->contentType) ?? $extension;
        }

        if ($extension === '' || $extension === 'html') {
            return $rawPath;
        }

        $target = $this->store->documentRawPath($site, $url, $extension);
        if ($target === $rawPath) {
            return $rawPath;
        }

        ensure_dir(dirname($target));

        if (@rename($rawPath, $target)) {
            return $target;
        }

        if (@copy($rawPath, $target)) {
            @unlink($rawPath);

            return $target;
        }

        return $rawPath;
    }

    /**
     * Proses satu URL: unduh HTML -> parse -> chunk -> embed -> simpan.
     *
     * $preFetched diisi bila HTML sudah diunduh pada batch yang sama
     * (konkurensi > 1) sehingga tidak ada unduhan kedua.
     *
     * $sumberUnduhan hanya label log: diisi 'berkas lokal' bila berkasnya sudah
     * ada di storage/documents dan tidak diunduh pada run ini (pemrosesan ulang
     * lewat `php bin/crawl.php documents`).
     *
     * @return array{ok: bool, links: list<string>, vectors: int, document: array<string, mixed>|null}
     */
    private function processUrl(
        SiteConfig $site,
        string $url,
        string $vectorFile,
        int $depth,
        int $maxDepth,
        bool $saveHtml,
        ?FetchResult $preFetched = null,
        ?string $sumberUnduhan = null,
    ): array {
        $id = $site->id;
        $stats = &$this->result->site($id);
        $documentId = MarkdownChunker::documentIdForUrl($url);
        $stats['url_diproses']++;

        // ------------------------------ FETCH ------------------------------
        // Path mentah: .pdf/.docx/... untuk entri dokumen (ekstensi asli
        // dipertahankan agar /parse memilih parser yang tepat), .html untuk
        // halaman biasa.
        $rawPath = $this->store->rawPath($site, $url);
        $document = $this->isDocumentUrl($site, $url);

        $this->logger->before('FETCH_BEFORE', [
            'kedalaman' => $depth,
            'document_id' => $documentId,
            'jenis' => $document ? ($site->documentMode ? 'dokumen' : 'dokumen_tautan') : 'html',
            'tujuan' => $this->store->relative($rawPath),
            'sumber' => $sumberUnduhan ?? ($preFetched === null ? 'unduh' : 'batch'),
        ], $sumberUnduhan !== null
            ? 'Memakai berkas dokumen yang sudah ada di storage (tanpa unduh ulang)'
            : ($preFetched === null
                ? ($document ? 'Mengunduh berkas dokumen' : 'Mengunduh HTML')
                : 'Memakai berkas hasil unduhan batch'));

        $fetch = $preFetched ?? $this->fetcher->fetch($site, $url, $rawPath);

        if (!$fetch->ok) {
            $this->result->fetchFailed++;
            $stats['gagal_unduh']++;
            $this->logger->after('FETCH_AFTER', Logger::STATUS_FAIL, [
                'http' => $fetch->httpStatus,
                'durasi' => Text::duration($fetch->durationSeconds),
            ], $fetch->error ?? 'gagal mengunduh');

            return ['ok' => false, 'links' => [], 'vectors' => 0, 'document' => null, 'document_path' => '', 'documents_auto' => []];
        }

        $this->result->urlsFetched++;
        $this->result->bytes += $fetch->bytes;
        $stats['byte'] += $fetch->bytes;

        // Path final dari fetcher: URL dokumen yang ternyata mengirim HTML akan
        // diganti ekstensinya menjadi .html oleh HtmlFetcher; sedangkan endpoint
        // tanpa ekstensi yang mengirim berkas ber-Content-Type dokumen sudah
        // dinamai .pdf/.xlsx oleh HtmlFetcher.
        $rawPath = $fetch->path ?? $rawPath;

        if (!$fetch->isHtml) {
            // Respons non-HTML = berkas DOKUMEN. Ini menangkap dua hal:
            //   a. URL yang sudah diperkirakan dokumen (mis. .pdf, atau endpoint
            //      "/front/dokumen/download/12"), dan
            //   b. URL yang baru ketahuan dokumen setelah diunduh (Content-Type
            //      application/pdf/... pada URL tanpa ekstensi).
            // Berkasnya dipindahkan ke storage/documents dengan ekstensi sesuai
            // Content-Type supaya /parse memilih parser yang tepat, dan mode
            // dokumen dipastikan aktif (render JS & content_api dilewati).
            $document = true;
            $rawPath = $this->promoteDocument($site, $url, $fetch, $rawPath);
            $fetch = $fetch->withPath($rawPath);

            $this->logger->event('DOK_MIME', Logger::STATUS_OK, [
                'url' => $url,
                'content_type' => $fetch->contentType ?? '-',
                'file' => $this->store->relative($rawPath),
            ], 'Respons bukan HTML: berkas diperlakukan sebagai dokumen dan dikirim ke /parse');
        } elseif (!$site->isDocument($url)) {
            // Respons HTML: dugaan "dokumen" dari kata pada URL (mis.
            // "/unduh-panduan.html", "/detail-unduh.html") BATAL. Halaman ini
            // tetap halaman biasa: render JS & aturan content_api tetap boleh
            // jalan dan payload.file_url tidak diisi.
            if ($document) {
                $this->logger->event('DOK_BATAL', Logger::STATUS_SKIP, [
                    'url' => $url,
                    'content_type' => $fetch->contentType ?? '-',
                ], 'URL diperkirakan dokumen tetapi responsnya HTML; diproses sebagai halaman biasa');
            }

            $document = false;
        }

        // Halaman dibaca sekali untuk ekstraksi tautan dan parser lokal; berkas
        // dokumen (biner) tidak pernah dibaca ke memori.
        $html = $fetch->isHtml ? $this->fetcher->read($rawPath) : '';

        // Berkas DOKUMEN yang tampak hasil pindai (gambar tanpa lapisan teks)
        // tidak bisa dibaca tanpa OCR. Keterangan ini dipakai pada log DOC_AFTER
        // di bawah supaya "PDF tidak menghasilkan point" tidak jadi misteri.
        $pindai = $document && !$fetch->isHtml && self::looksLikeScannedPdf((string) ($fetch->path ?? ''));

        $this->logger->after('FETCH_AFTER', Logger::STATUS_OK, [
            'http' => $fetch->httpStatus,
            'byte' => Text::humanBytes($fetch->bytes),
            'content_type' => $fetch->contentType ?? '-',
            'charset' => $fetch->charset ?? 'auto',
            'ip' => $fetch->remoteIp !== '' ? $fetch->remoteIp : '-',
            'durasi' => Text::duration($fetch->durationSeconds),
            'file' => $sumberUnduhan !== null || $saveHtml ? $this->store->relative($rawPath) : 'sementara',
        ], $sumberUnduhan !== null
            ? 'Berkas dokumen lokal dipakai (sudah ada di storage/documents)'
            : ($fetch->isHtml ? 'HTML berhasil diunduh' : 'Berkas dokumen berhasil diunduh'));

        if ($fetch->insecureTls) {
            $this->warnInsecureTls($url);
        }

        if ($fetch->addressRetry) {
            $this->warnAddressRetry($url, $fetch->remoteIp);
        }

        // ------------- KONTEN (RENDER JS -> API SITUS -> PARSE) --------------
        // Situs berbasis JavaScript mengirim HTML kerangka (menu, tagline,
        // footer) saat halaman diunduh tanpa menjalankan JavaScript sehingga isi
        // halaman tidak ikut terkirim. Urutan pengambilan isi:
        //   1. render halaman dengan browser headless — cara bawaan, tidak butuh
        //      aturan API apa pun pada config/sites.json,
        //   2. aturan "content_api" pada entri site (cadangan bila render gagal),
        //   3. HTML hasil unduhan apa adanya lewat /parse.
        // HTML untuk menelusuri tautan ikut memakai DOM hasil render supaya
        // tautan yang baru dibuat JavaScript (mis. daftar berita) dikenali.
        $htmlTautan = $html;
        $sumberKonten = 'html';
        $judulApi = '';
        $markdown = '';
        $title = '';
        $parseMode = '';

        $render = $this->renderPage($site, $url, $document, $fetch, $rawPath, $html, $saveHtml);

        // Berkas dokumen yang hanya muncul lewat JavaScript/API halaman ini
        // ditemukan dari log jaringan hasil render (tanpa aturan per situs).
        $dokumenOtomatis = $this->harvestDocuments($site, $url, $render !== null);

        if ($render !== null) {
            [$markdown, $title, $parseMode] = $this->parseDocument($site, $render['fetch'], $url);

            if (trim($markdown) !== '') {
                $htmlTautan = $render['html'];
                $sumberKonten = 'render';
            }
        }

        if (trim($markdown) === '') {
            $hasilApi = $this->apiContent($site, $url, $document);

            if ($hasilApi !== null) {
                $markdown = $hasilApi['markdown'];
                $title = $hasilApi['title'];
                $parseMode = $hasilApi['mode'];
                $judulApi = $hasilApi['title'];
                $sumberKonten = 'api';
            }
        }

        if (trim($markdown) === '') {
            [$markdown, $title, $parseMode] = $this->parseDocument($site, $fetch, $url);
        }

        // Berkas HTML mentah dihapus bila save_html=false (--no-save-html).
        // Berkas DOKUMEN mentah TIDAK dihapus di sini: penghapusannya menunggu
        // vektornya terkirim ke Qdrant (delete_documents_after_push) supaya
        // berkasnya masih ada bila parse/embed/push perlu diulang.
        if (!$saveHtml && !$document) {
            @unlink($rawPath);
        }

        if (trim($markdown) === '') {
            $this->result->parseFailed++;
            $this->result->sites[$id]['parse_gagal']++;
            $this->logger->after('DOC_AFTER', Logger::STATUS_FAIL, [
                'document_id' => $documentId,
                'tahap' => 'parse',
                'pindai' => $pindai ? 'ya' : 'tidak',
            ], $pindai
                ? 'Dokumen tidak menghasilkan markdown: berkas tampak hasil PINDAI/gambar (tanpa lapisan teks) sehingga butuh OCR pada layanan /parse; berkas mentah tetap disimpan'
                : 'Dokumen gagal diparse, tidak ada chunk yang dikirim ke /embed; berkas mentah tetap disimpan'
                    . ($document ? ' -> proses ulang tanpa crawl: php bin/crawl.php documents --site=' . $site->id : ''));

            return [
                'ok' => false,
                'links' => [],
                'vectors' => 0,
                'document' => null,
                'document_path' => '',
                'documents_auto' => $dokumenOtomatis,
            ];
        }

        // ------------------------- BERSIHKAN MARKDOWN ----------------------
        // Menu navigasi, ornament, dan footer hasil /parse dibuang lebih dahulu
        // supaya yang di-chunk (dan dikirim ke /embed serta disimpan ke Qdrant)
        // benar-benar isi halaman. Pembersihan deterministik sehingga checksum
        // dokumen tetap stabil antar re-crawl.
        $markdownAsli = $markdown;

        if (!$site->cleanMarkdown) {
            $this->logger->event('MARKDOWN_CLEAN', Logger::STATUS_SKIP, [
                'site' => $site->id,
            ], 'Pembersihan markdown dilewati (clean_markdown=false / CHUNK_CLEAN_MARKDOWN=false)');
        } else {
            $bersih = MarkdownCleaner::clean($markdown);
            $minimum = max(1, $site->minChunkLength);

            if (mb_strlen($bersih['markdown']) >= $minimum) {
                $markdown = $bersih['markdown'];

                $this->logger->info('MARKDOWN_CLEAN', 'Markdown dibersihkan dari menu/ornamen/footer', [
                    'karakter_markdown' => mb_strlen($markdownAsli),
                    'karakter_bersih' => mb_strlen($markdown),
                    'berkurang' => self::persenBerkurang(mb_strlen($markdownAsli), mb_strlen($markdown)),
                ] + $bersih['dihapus']);
            } else {
                // Halaman yang (hampir) seluruhnya menu: markdown asli tetap
                // dipakai agar dokumen tidak hilang, tetapi baris footer/boilerplate
                // (versi aplikasi, surel, alamat kantor, hak cipta) tetap dibuang
                // supaya tidak ikut dikirim ke /embed maupun ke payload Qdrant.
                $tanpaFooter = MarkdownCleaner::dropBoilerplateLines($markdownAsli);
                $markdown = $tanpaFooter['markdown'];
                $this->logger->event('MARKDOWN_CLEAN', Logger::STATUS_SKIP, [
                    'karakter_markdown' => mb_strlen($markdownAsli),
                    'karakter_bersih' => mb_strlen($bersih['markdown']),
                    'karakter_tanpa_footer' => mb_strlen($markdown),
                    'baris_footer' => $tanpaFooter['dihapus'],
                    'minimal' => $minimum,
                ] + $bersih['dihapus'], 'Hasil pembersihan lebih pendek dari min_chunk_length; markdown asli dipakai tanpa baris footer');
            }
        }

        // Judul yang dipakai payload vektor + berkas markdown ditentukan SETELAH
        // markdown dibersihkan: /parse selalu mengembalikan NAMA BERKAS yang
        // diunggah (mis. "www-kemendagri-go-id-beranda--0d19ccff.html") sebagai
        // judul, dan heading pertama markdown mentah biasanya masih heading menu
        // situs ("# Kementerian Dalam Negeri"). Heading pertama markdown bersih
        // adalah judul halaman yang sebenarnya.
        //
        // Berkas dari API situs (aturan "document_api") belum punya judul dari
        // /parse karena nama berkasnya (mis. "informasi-public-1758855835835.pdf")
        // bukan judul; judul item dari API dipakai sebagai kandidat pertama.
        if ($judulApi === '') {
            $judulApi = (string) ($this->documentApiUrls[$site->id][$url] ?? '');
        }

        $title = $this->resolveTitle($site, $title, $markdown, $fetch->isHtml ? $htmlTautan : '', $url, $rawPath, $judulApi);

        $markdownPath = $this->store->markdownPath($site, $url);
        $this->store->writeMarkdown($markdownPath, $url, $title, $markdown, [
            'site' => $site->id,
            'document_id' => $documentId,
            'jenis' => $document ? 'dokumen' : 'html',
            'document_type' => $site->documentType(),
            'mode_parse' => $parseMode,
        ]);

        $this->logger->info('MARKDOWN', 'Markdown disimpan sebagai berkas teks', [
            'file' => $this->store->relative($markdownPath),
            'karakter' => mb_strlen($markdown),
            'mode_parse' => $parseMode,
            'sumber_konten' => $sumberKonten,
        ]);

        // ------------- KONTEN DUPLIKAT / MIRIP (BOILERPLATE) -----------------
        // Halaman pada situs berbasis JavaScript (mis. halaman daftar /berita,
        // /publikasi, /media) bisa menghasilkan isi yang sama atau hampir sama
        // karena yang terbaca hanya menu/tagline/footer. Isi seperti itu bukan
        // isi halaman, jadi hanya URL pertama yang dikirim ke /embed; URL
        // berikutnya dicatat pada log dan tautannya tetap ditelusuri.
        // Perbandingan memakai sidik jari kata (kemiripan >=
        // duplicate_similarity), bukan hanya kesamaan persis, supaya sisa
        // boilerplate yang berbeda beberapa karakter tetap tertolak.
        if ($site->dropDuplicateContent) {
            $sidik = sha1($markdown);
            $pertama = $this->contentSeen[$id][$sidik] ?? null;
            $tokens = self::contentTokens($markdown);
            $mirip = $pertama === null
                ? $this->similarContent($id, $tokens, $site->duplicateSimilarity)
                : null;

            if (($pertama !== null && $pertama !== $url) || $mirip !== null) {
                $this->result->contentDuplicates++;
                $stats['konten_duplikat']++;

                $this->logger->event('CONTENT_DUPLIKAT', Logger::STATUS_SKIP, [
                    'document_id' => $documentId,
                    'jenis' => $pertama !== null ? 'identik' : 'mirip',
                    'kemiripan' => $pertama !== null ? '1.000' : number_format((float) $mirip['skor'], 3, '.', ''),
                    'url_pertama' => $pertama ?? (string) $mirip['url'],
                    'karakter_markdown' => mb_strlen($markdown),
                    'sumber_konten' => $sumberKonten,
                ], 'Isi halaman sama/mirip dengan URL lain pada site ini (boilerplate); chunk tidak dikirim ke /embed');

                $links = $document ? [] : $this->extractLinks($site, $htmlTautan, $fetch->effectiveUrl, $depth, $maxDepth);

                return [
                    'ok' => true,
                    'links' => $links,
                    'vectors' => 0,
                    'document' => null,
                    'document_path' => '',
                    'documents_auto' => $dokumenOtomatis,
                ];
            }

            $this->contentSeen[$id][$sidik] = $url;
            $this->rememberContent($id, $url, $tokens);
        }

        // ------------------------------ CHUNK ------------------------------
        // payload.object_key: path berkas mentah di storage. Berkas dokumen yang
        // akan dihapus setelah dikirim ke Qdrant (delete_documents_after_push)
        // dikirim null supaya payload tidak menunjuk berkas yang sudah hilang;
        // payload.file_url tetap berisi URL asli dokumennya.
        $berkasDisimpan = $document ? !$site->deleteDocumentsAfterPush : $saveHtml;
        $objectKey = $berkasDisimpan ? $this->store->relative($rawPath) : null;
        // payload.file_url diisi HANYA untuk dokumen: URL berkas mentah
        // (mengikuti pengalihan) supaya aplikasi hilir bisa membuka PDF/lampiran
        // langsung dari notifikasi Qdrant. Halaman HTML tetap null.
        $fileUrl = $document ? $fetch->effectiveUrl : null;
        $chunkDibuang = null;
        $chunks = MarkdownChunker::build($title, $url, $site, $markdown, null, $objectKey, $chunkDibuang, $fileUrl);
        $chunkDibuang ??= ['pendek' => 0, 'tanpa_isi' => 0, 'tautan' => 0];

        if ($chunks === []) {
            $this->logger->after('DOC_AFTER', Logger::STATUS_SKIP, [
                'document_id' => $documentId,
                'tahap' => 'chunk',
                'karakter_markdown' => mb_strlen($markdown),
                'dibuang_pendek' => $chunkDibuang['pendek'],
                'dibuang_tanpa_isi' => $chunkDibuang['tanpa_isi'],
                'dibuang_tautan' => $chunkDibuang['tautan'],
                'pindai' => $pindai ? 'ya' : 'tidak',
            ], $pindai
                ? 'Teks markdown terlalu pendek karena berkas dokumen tampak hasil PINDAI/gambar (tanpa lapisan teks); butuh OCR pada layanan /parse agar isinya bisa diindeks'
                : 'Tidak ada chunk yang memenuhi min_chunk_length, tidak ada yang dikirim ke /embed');

            return [
                'ok' => true,
                'links' => [],
                'vectors' => 0,
                'document' => null,
                'document_path' => '',
                'documents_auto' => $dokumenOtomatis,
            ];
        }

        $this->result->documents++;
        $this->result->chunks += count($chunks);
        $stats['dokumen']++;
        $stats['chunk'] += count($chunks);

        $this->logger->info('CHUNK', 'Markdown dipecah menjadi chunk', [
            'chunk' => count($chunks),
            'ukuran' => $site->chunkSize,
            'overlap' => $site->chunkOverlap,
            'min_panjang' => $site->minChunkLength,
            'batas_tautan' => $site->maxLinkRatio,
            'dibuang_pendek' => $chunkDibuang['pendek'],
            'dibuang_tanpa_isi' => $chunkDibuang['tanpa_isi'],
            'dibuang_tautan' => $chunkDibuang['tautan'],
        ]);

        // ------------------------------ EMBED ------------------------------
        $expectedDimension = (int) ($this->embedConfig['expected_dimension'] ?? 1024);
        $maxCharsPerBatch = max(0, (int) ($this->embedConfig['max_chars_per_batch'] ?? 40000));
        $batches = self::batchChunks($chunks, $site->embedBatchSize, $maxCharsPerBatch);
        $records = [];
        $embedded = 0;
        $failed = 0;

        $this->logger->before('EMBED_BEFORE', [
            'endpoint' => $this->service->embedUrl(),
            'chunk' => count($chunks),
            'batch' => count($batches),
            'ukuran_batch' => $site->embedBatchSize,
            'batas_karakter_batch' => $maxCharsPerBatch,
            'dimensi_diharapkan' => $expectedDimension,
        ], 'Mengirim ' . count($chunks) . ' chunk ke /embed');

        foreach ($batches as $index => $batch) {
            $texts = [];
            foreach ($batch as $chunk) {
                $texts[] = $chunk->content;
            }

            $embed = $this->service->embed($texts);
            $batchLabel = ($index + 1) . '/' . count($batches);

            if (!$embed->ok || $embed->count() !== count($batch)) {
                $failed += count($batch);
                $this->result->embedFailed += count($batch);
                $stats['vektor_gagal'] += count($batch);
                $this->logger->event('EMBED_BATCH', Logger::STATUS_FAIL, [
                    'batch' => $batchLabel,
                    'chunk' => count($batch),
                    'http' => $embed->httpStatus,
                    'percobaan' => $embed->attempts,
                    'durasi' => Text::duration($embed->durationSeconds),
                ], $embed->error ?? 'jumlah vektor tidak sesuai jumlah chunk');

                continue;
            }

            if ($embed->dimension !== $expectedDimension) {
                $failed += count($batch);
                $this->result->embedFailed += count($batch);
                $stats['vektor_gagal'] += count($batch);
                $this->logger->event('EMBED_BATCH', Logger::STATUS_FAIL, [
                    'batch' => $batchLabel,
                    'chunk' => count($batch),
                    'dimensi' => $embed->dimension,
                    'dimensi_diharapkan' => $expectedDimension,
                ], 'Dimensi vektor tidak sesuai harapan, batch dibuang');

                continue;
            }

            foreach ($batch as $position => $chunk) {
                if (isset($embed->embeddings[$position])) {
                    $records[] = $chunk->withVector($embed->embeddings[$position]);
                }
            }

            $embedded += count($batch);
            $this->result->embedded += count($batch);
            $stats['vektor'] += count($batch);

            $this->logger->event('EMBED_BATCH', Logger::STATUS_OK, [
                'batch' => $batchLabel,
                'vektor' => $embed->count(),
                'dimensi' => $embed->dimension,
                'durasi' => Text::duration($embed->durationSeconds),
            ], 'Batch berhasil di-embed');
        }

        $written = $this->store->appendVectors($vectorFile, $records);

        $this->logger->after('EMBED_AFTER', $embedded > 0 ? Logger::STATUS_OK : Logger::STATUS_FAIL, [
            'vektor' => $embedded,
            'vektor_gagal' => $failed,
            'chunk' => count($chunks),
            'dimensi' => $expectedDimension,
            'file' => $written > 0 ? $this->store->relative($vectorFile) : '-',
        ], $embedded > 0 ? 'Vektor siap dipakai tahap Qdrant' : 'Tidak ada vektor yang berhasil ditulis');

        // ------------------------------ TAUTAN ------------------------------
        // Tautan diambil dari DOM hasil render bila halaman dirender: banyak
        // situs SPA baru menulis daftar tautannya setelah JavaScript jalan
        // (mis. daftar berita), sehingga tanpa itu crawler tidak menemukan
        // halaman detail apa pun.
        $links = $this->extractLinks($site, $htmlTautan, $fetch->effectiveUrl, $depth, $maxDepth);

        $this->logger->after('DOC_AFTER', Logger::STATUS_OK, [
            'document_id' => $documentId,
            'judul' => $title,
            'mode_parse' => $parseMode,
            'karakter_markdown' => mb_strlen($markdown),
            'chunk' => count($chunks),
            'vektor' => $embedded,
            'tautan_ditemukan' => count($links),
        ], 'Dokumen selesai diproses');

        return [
            'ok' => true,
            'links' => $links,
            'vectors' => $written,
            'document' => [
                'document_id' => $documentId,
                'url' => $url,
                'judul' => $title,
                'jenis' => $document ? 'dokumen' : 'html',
                'mode_parse' => $parseMode,
                'chunk' => count($chunks),
                'vektor' => $embedded,
                'markdown' => $this->store->relative($markdownPath),
                'berkas' => $this->store->relative($rawPath),
            ],
            // Path absolut berkas DOKUMEN mentah (kosong untuk halaman HTML):
            // dipakai crawlSite() untuk menghapus berkas setelah dikirim ke
            // Qdrant (crawl.delete_documents_after_push).
            'document_path' => $document ? $rawPath : '',
            // Berkas dokumen hasil penemuan otomatis dari log jaringan halaman
            // ini (url => judul): crawlSite() menyisipkannya di DEPAN antrean.
            'documents_auto' => $dokumenOtomatis,
        ];
    }
    /**
     * Unggah berkas vektor satu site ke Qdrant tepat setelah tahap /embed
     * selesai (QDRANT_PUSH_AFTER_CRAWL=true, bawaan). Pengaturan yang dipakai
     * persis sama dengan perintah `php bin/crawl.php qdrant`, termasuk
     * QDRANT_VECTOR_SIZE, QDRANT_DISTANCE, dan siklus hidup collection
     * (QDRANT_RECREATE / QDRANT_AUTO_CREATE).
     *
     * Kegagalan unggah TIDAK menggagalkan crawl: markdown, berkas .jsonl, dan
     * .qdrant.json tetap tersimpan, kegagalan dicatat sebagai QDRANT_PUSH pada
     * log txt, lalu bisa diulang manual:
     *   php bin/crawl.php qdrant --file=storage/vectors/<site>/<run>.jsonl
     *
     * @return array<string, mixed> ringkasan hasil unggah (manifest + ringkasan run)
     */
    private function pushToQdrant(string $vectorFile, SiteConfig $site): array
    {
        $collection = (string) ($this->qdrantConfig['collection'] ?? 'documents');
        $baseUrl = (string) ($this->qdrantConfig['base_url'] ?? 'http://localhost:6333');

        $stats = [
            'site' => $site->id,
            'collection' => $collection,
            'url_qdrant' => $baseUrl,
            'dimensi' => 0,
            'point_terkirim' => 0,
            'point_gagal' => 0,
            'collection_dibuat' => false,
            'status' => 'SUKSES',
            'catatan' => '',
        ];

        try {
            $client = new QdrantClient(
                $this->http,
                $baseUrl,
                (string) ($this->qdrantConfig['api_key'] ?? ''),
                QdrantClient::timeoutSeconds($this->qdrantConfig['timeout'] ?? 120),
            );

            $push = new QdrantPush($this->qdrantConfig, $this->config['paths'] ?? [], $client, [
                'file' => $vectorFile,
                'collection' => $collection,
                'distance' => (string) ($this->qdrantConfig['distance'] ?? 'Cosine'),
                'batch' => max(1, (int) ($this->qdrantConfig['batch_size'] ?? 64)),
                'vector_size' => max(0, (int) ($this->qdrantConfig['vector_size'] ?? 0)),
                'recreate' => (bool) ($this->qdrantConfig['recreate'] ?? false),
            ], $this->logger);

            $hasil = $push->run();

            $stats['dimensi'] = (int) $hasil['dimensi'];
            $stats['point_terkirim'] = (int) $hasil['point_terkirim'];
            $stats['point_gagal'] = (int) $hasil['point_gagal'];
            $stats['collection_dibuat'] = (bool) $hasil['collection_dibuat'];
            $stats['status'] = (string) $hasil['status'];
        } catch (Throwable $exception) {
            $stats['status'] = 'GAGAL';
            $stats['catatan'] = Text::oneLine($exception->getMessage(), 300);

            $this->logger->event('QDRANT_PUSH', Logger::STATUS_FAIL, [
                'site' => $site->id,
                'collection' => $collection,
                'qdrant' => $baseUrl,
                'berkas' => $this->store->relative($vectorFile),
                'galat' => $stats['catatan'],
            ], 'Push otomatis ke Qdrant gagal; crawl tetap sukses, ulangi dengan '
                . 'php bin/crawl.php qdrant --file=' . $this->store->relative($vectorFile));
        }

        if ($stats['status'] !== 'GAGAL') {
            $this->logger->event(
                'QDRANT_PUSH',
                $stats['status'] === 'SUKSES' ? Logger::STATUS_OK : Logger::STATUS_SKIP,
                [
                    'site' => $site->id,
                    'collection' => $collection,
                    'qdrant' => $baseUrl,
                    'berkas' => $this->store->relative($vectorFile),
                    'dimensi' => $stats['dimensi'],
                    'terkirim' => $stats['point_terkirim'],
                    'gagal' => $stats['point_gagal'],
                    'collection_dibuat' => $stats['collection_dibuat'] ? 'true' : 'false',
                ],
                'Push otomatis ke Qdrant selesai'
            );
        }

        $this->result->qdrantPushed += $stats['point_terkirim'];
        $this->result->qdrantFailed += $stats['point_gagal'];
        $this->result->qdrantRuns[] = $stats;

        return $stats;
    }



    /**
     * Catat sekali per host bila permintaan berhasil tanpa verifikasi TLS.
     *
     * Terjadi pada situs yang mengirim rantai sertifikat tidak lengkap; curl
     * gagal dengan error 60 lalu permintaan diulang tanpa verifikasi agar
     * unduhan tetap berjalan. Dicatat pada log txt supaya tetap transparan.
     */
    private function warnInsecureTls(string $url): void
    {
        $host = Text::hostname($url);

        if ($host === '' || isset($this->tlsWarned[$host])) {
            return;
        }

        $this->tlsWarned[$host] = true;

        $this->logger->event('TLS_INSECURE', Logger::STATUS_SKIP, [
            'host' => $host,
            'contoh_url' => Text::oneLine($url, 120),
        ], 'Rantai sertifikat server tidak lengkap (curl error 60); unduhan diulang tanpa verifikasi TLS. '
            . 'Ubah CRAWLER_VERIFY_TLS / CRAWLER_INSECURE_RETRY bila perilaku ini tidak diinginkan.');
    }

    /**
     * Catat (sekali per host) bahwa unduhan berhasil setelah dicoba ulang ke
     * alamat IP lain: host mengumumkan beberapa alamat A dan alamat yang dicoba
     * lebih dahulu tidak menjawab.
     */
    private function warnAddressRetry(string $url, string $ip): void
    {
        $host = Text::hostname($url);

        if ($host === '' || isset($this->addressWarned[$host])) {
            return;
        }

        $this->addressWarned[$host] = true;

        $this->logger->event('ADDR_RETRY', Logger::STATUS_SKIP, [
            'host' => $host,
            'alamat_dipakai' => $ip !== '' ? $ip : '-',
            'contoh_url' => Text::oneLine($url, 120),
        ], 'Alamat pertama host ini tidak menjawab (koneksi timeout); unduhan diulang ke alamat hasil resolusi lain '
            . 'dan alamat itu dipakai untuk sisa run. Ubah CRAWLER_RETRY_ADDRESSES / CRAWLER_CONNECT_TIMEOUT '
            . 'bila perilaku ini tidak diinginkan.');
    }

    /**
     * Isi halaman dari API resmi situs (aturan "content_api" pada
     * config/sites.json) untuk situs yang halaman HTML-nya hanya kerangka.
     *
     * Null berarti: site tidak punya aturan, URL ini tidak cocok dengan aturan
     * mana pun, entri berupa dokumen, atau endpoint gagal/kosong. Pemanggil
     * memakai hasil /parse seperti biasa; kegagalan sudah dicatat pada log.
     *
     * @return array{markdown: string, title: string, mode: string}|null
     */
    /**
     * Render halaman dengan browser headless bila isinya baru dibuat JavaScript.
     *
     * Mode "auto" (bawaan) hanya merender halaman yang HTML-nya kerangka SPA,
     * "always" merender setiap halaman HTML, dan "off" tidak pernah merender.
     * DOM hasil render ditulis menimpa berkas mentah supaya /parse, parser
     * lokal, simpanan storage/html, dan payload Qdrant memakai HTML yang sudah
     * berisi isi halaman.
     *
     * @return array{fetch: FetchResult, html: string}|null null bila tidak dirender
     */
    private function renderPage(
        SiteConfig $site,
        string $url,
        bool $document,
        FetchResult $fetch,
        string $rawPath,
        string $html,
        bool $saveHtml,
    ): ?array {
        if ($document || !$fetch->isHtml || $html === '') {
            return null;
        }

        $setelan = $site->jsRender;
        $mode = $this->renderOverride ?? (string) ($setelan['mode'] ?? 'auto');

        if ($mode === 'off') {
            return null;
        }

        if ($mode !== 'always' && !HeadlessRenderer::looksJsOnly(
            $html,
            (int) ($setelan['min_text'] ?? 2000),
            (float) ($setelan['ratio'] ?? 0.03)
        )) {
            return null;
        }

        if (!$this->renderer->available()) {
            $this->logger->event('RENDER_SKIP', Logger::STATUS_SKIP, [
                'galat' => $this->renderer->lastError(),
            ], 'Halaman terdeteksi kerangka JavaScript tetapi browser headless tidak tersedia; HTML unduhan dipakai apa adanya');

            return null;
        }

        $this->logger->before('RENDER_BEFORE', [
            'mode' => $mode,
            'browser' => basename((string) $this->renderer->bin()),
            'tunggu' => (int) ($setelan['wait_ms'] ?? 6000) . 'ms',
            'timeout' => (int) ($setelan['timeout_ms'] ?? 25000) . 'ms',
            'penanda' => implode(',', HeadlessRenderer::jsMarkers($html)) ?: '-',
            'teks_unduhan' => mb_strlen(HeadlessRenderer::visibleText($html)),
        ], 'Isi halaman dibuat JavaScript; halaman dirender dengan browser headless');

        $hasil = $this->renderer->render($url, $setelan);
        $stats = &$this->result->site($site->id);

        if ($hasil === null) {
            $this->result->renderFailed++;
            $stats['render_gagal']++;

            $this->logger->after('RENDER_AFTER', Logger::STATUS_FALLBACK, [
                'galat' => $this->renderer->lastError(),
            ], 'Render gagal; HTML hasil unduhan tetap dipakai');

            return null;
        }

        // Skrip/gaya/SVG/payload data hasil render dibuang dulu: yang di-parse
        // (dan disimpan ke storage/html) hanya bagian isi halaman.
        $dom = HeadlessRenderer::stripNonContent($hasil['html']);
        if (strlen($dom) < 500) {
            // Hasil pembersihan mencurigakan: pakai DOM apa adanya.
            $dom = $hasil['html'];
        }

        if (@file_put_contents($rawPath, $dom) === false) {
            $stats['render_gagal']++;
            $this->logger->after('RENDER_AFTER', Logger::STATUS_FAIL, [
                'file' => $this->store->relative($rawPath),
            ], 'DOM hasil render tidak bisa ditulis; HTML hasil unduhan tetap dipakai');

            return null;
        }

        $this->result->jsRendered++;
        $stats['render_js']++;

        $this->logger->after('RENDER_AFTER', Logger::STATUS_OK, [
            'durasi' => Text::duration((float) $hasil['seconds']),
            'byte' => Text::humanBytes(strlen($dom)),
            'byte_dom' => Text::humanBytes((int) $hasil['bytes']),
            'teks_render' => mb_strlen(HeadlessRenderer::visibleText($dom)),
            'file' => $saveHtml ? $this->store->relative($rawPath) : 'sementara',
        ], 'DOM hasil render (tanpa skrip/gaya) dipakai sebagai isi halaman');

        return [
            'fetch' => new FetchResult(
                url: $fetch->url,
                effectiveUrl: $fetch->effectiveUrl,
                httpStatus: $fetch->httpStatus,
                ok: true,
                isHtml: true,
                contentType: 'text/html',
                bytes: strlen($dom),
                durationSeconds: (float) $hasil['seconds'],
                path: $rawPath,
                charset: 'utf-8',
                error: null,
                insecureTls: $fetch->insecureTls,
            ),
            'html' => $dom,
        ];
    }

    /**
     * Deskripsi setelan render browser headless untuk log RUN_START.
     */
    private function rendererInfo(): string
    {
        $mode = $this->renderOverride ?? $this->renderer->mode();
        $bin = $this->renderer->bin();

        return $mode . ($bin === null
            ? ' (browser headless tidak ditemukan: ' . $this->renderer->lastError() . ')'
            : ' (browser: ' . basename($bin) . ')');
    }

    private function apiContent(SiteConfig $site, string $url, bool $document): ?array
    {
        if ($document || !$this->contentApi->hasRules($site)) {
            return null;
        }

        $rule = $this->contentApi->ruleFor($site, $url);
        if ($rule === null) {
            return null;
        }

        $this->logger->before('CONTENT_API_BEFORE', [
            'endpoint' => (string) $rule['url'],
            'field' => (string) $rule['field'] === '' ? '<badan>' : (string) $rule['field'],
            'format' => (string) $rule['format'],
            'minimal' => (int) $rule['min_length'],
        ], 'Situs berbasis JavaScript: mengambil isi halaman dari API situs');

        $error = null;
        $hasil = $this->contentApi->fetch($rule, $url, $site, $error);

        if ($hasil === null) {
            $this->logger->after('CONTENT_API_AFTER', Logger::STATUS_FALLBACK, [
                'endpoint' => (string) $rule['url'],
                'galat' => (string) $error,
            ], 'Isi halaman tidak tersedia dari API situs, memakai /parse (HTML kerangka)');

            return null;
        }

        $this->result->contentApi++;
        $stats = &$this->result->site($site->id);
        $stats['konten_api']++;

        $this->logger->after('CONTENT_API_AFTER', Logger::STATUS_OK, [
            'endpoint' => $hasil['endpoint'],
            'field' => $hasil['field'],
            'http' => $hasil['http'],
            'judul' => $hasil['title'],
            'karakter_markdown' => mb_strlen($hasil['markdown']),
        ], 'Isi halaman diterima dari API situs');

        return [
            'markdown' => $hasil['markdown'],
            'title' => $hasil['title'],
            'mode' => 'api',
        ];
    }

    /**
     * Sidik jari isi halaman: kata unik (>= 3 huruf) pada markdown tanpa markup
     * tautan, supaya perbedaan URL/tautan tidak dianggap isi yang berbeda.
     *
     * @return array<string, bool>
     */
    private static function contentTokens(string $markdown, int $maxTokens = 600): array
    {
        $teks = preg_replace('/!?\[([^\]]*)\]\([^)]*\)/u', '$1', $markdown) ?? $markdown;
        $teks = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($teks, 'UTF-8')) ?? $teks;

        $tokens = [];
        foreach (preg_split('/\s+/u', trim($teks)) ?: [] as $kata) {
            if (mb_strlen($kata) < 3) {
                continue;
            }

            $tokens[$kata] = true;

            if (count($tokens) >= max(1, $maxTokens)) {
                break;
            }
        }

        return $tokens;
    }

    /**
     * Cari isi yang sudah pernah dikirim pada site yang sama dengan kemiripan
     * kata (Jaccard) di atas ambang. Dipakai agar isi boilerplate yang berbeda
     * hanya sedikit antar halaman tetap tidak ikut ke Qdrant.
     *
     * @param array<string, bool> $tokens
     *
     * @return array{url: string, skor: float}|null
     */
    private function similarContent(string $siteId, array $tokens, float $threshold): ?array
    {
        if ($tokens === [] || $threshold >= 1.0) {
            return null;
        }

        $terbaik = null;
        $skorTerbaik = 0.0;

        foreach ($this->contentFingerprints[$siteId] ?? [] as $sidik) {
            $irisan = count(array_intersect_key($tokens, $sidik['tokens']));
            if ($irisan === 0) {
                continue;
            }

            $gabungan = count($tokens) + count($sidik['tokens']) - $irisan;
            $skor = $gabungan === 0 ? 0.0 : $irisan / $gabungan;

            if ($skor > $skorTerbaik) {
                $skorTerbaik = $skor;
                $terbaik = $sidik['url'];
            }
        }

        if ($terbaik === null || $skorTerbaik < $threshold) {
            return null;
        }

        return ['url' => $terbaik, 'skor' => round($skorTerbaik, 4)];
    }

    /**
     * Simpan sidik jari isi yang sudah lolos agar halaman berikutnya pada site
     * yang sama bisa dibandingkan (dibatasi supaya memori tetap kecil).
     *
     * @param array<string, bool> $tokens
     */
    private function rememberContent(string $siteId, string $url, array $tokens): void
    {
        if ($tokens === []) {
            return;
        }

        $this->contentFingerprints[$siteId][] = ['url' => $url, 'tokens' => $tokens];

        if (count($this->contentFingerprints[$siteId]) > 300) {
            array_shift($this->contentFingerprints[$siteId]);
        }
    }

    /**
     * Parse berkas mentah hasil unduhan menjadi markdown.
     *
     * Urutan: POST /parse (utama) -> parser HTML -> Markdown lokal (cadangan).
     * Parser lokal hanya berlaku untuk HTML; berkas dokumen (pdf/docx/xlsx)
     * wajib diparse oleh layanan /parse karena pemilihan parser di sana
     * mengikuti ekstensi berkas.
     *
     * @return array{0: string, 1: string, 2: string} [markdown, judul, mode]
     */
    private function parseDocument(SiteConfig $site, FetchResult $fetch, string $url): array
    {
        $stats = &$this->result->site($site->id);
        $path = (string) ($fetch->path ?? '');
        $isHtml = $fetch->isHtml;
        $size = is_file($path) ? Text::humanBytes((int) filesize($path)) : '-';

        $reason = null;

        if (!$this->useParseService) {
            $reason = 'service /parse dilewati (parse_fallback.try_service=false)';
            $this->logger->event('PARSE_SKIP', Logger::STATUS_SKIP, [
                'url' => $url,
                'file' => basename($path),
                'ukuran' => $size,
            ], $reason);
        } else {
            $this->logger->before('PARSE_BEFORE', [
                'endpoint' => $this->service->parseUrl(),
                'file' => basename($path),
                'ukuran' => $size,
                'jenis' => $isHtml ? 'html' : 'dokumen',
                'mime' => IngestServiceClient::mimeTypeFor($path),
                'timeout' => (int) ($this->config['service']['parse_timeout'] ?? 600),
            ], $isHtml ? 'Mengirim HTML ke /parse' : 'Mengirim berkas dokumen ke /parse');

            $result = $this->service->parse($path);

            $minimumMarkdown = max(1, (int) ($this->embedConfig['min_chunk_length']
                ?? $this->crawlConfig['min_chunk_length']
                ?? ($this->config['chunk']['min_length'] ?? 50)));

            if ($result->ok && trim($result->markdown) !== ''
                && (!$isHtml || $result->markdownLength() >= $minimumMarkdown)) {
                $this->result->parseService++;
                $stats['parse_service']++;
                $this->logger->after('PARSE_AFTER', Logger::STATUS_OK, [
                    'mode' => 'service',
                    'http' => $result->httpStatus,
                    'halaman' => $result->pageCount,
                    'judul' => $result->title,
                    'markdown_karakter' => $result->markdownLength(),
                    'percobaan' => $result->attempts,
                    'durasi' => Text::duration($result->durationSeconds),
                ], 'Markdown diterima dari /parse');

                return [$result->markdown, $result->title, 'service'];
            }

            $reason = $result->ok
                ? (trim($result->markdown) === ''
                    ? 'markdown dari /parse kosong'
                    : 'markdown dari /parse terlalu pendek (' . $result->markdownLength()
                        . ' karakter, minimal ' . $minimumMarkdown . ')')
                : ($result->webIngestDisabled
                    ? 'service menolak ingest web (EMBED_WEB_PAGE=false pada worker-ingest)'
                    : 'gagal memanggil /parse' . ($result->error !== null ? ': ' . $result->error : ''));

            $this->logger->after('PARSE_AFTER', Logger::STATUS_FAIL, [
                'mode' => 'service',
                'http' => $result->httpStatus,
                'percobaan' => $result->attempts,
                'durasi' => Text::duration($result->durationSeconds),
            ], $reason);
        }

        if (!$isHtml) {
            // Berkas dokumen biner: parser HTML lokal tidak bisa membacanya.
            // PDF hasil pindai (tanpa lapisan teks) tidak bisa dibaca siapa pun
            // tanpa OCR, jadi penyebabnya ditulis eksplisit di log.
            $pindai = self::looksLikeScannedPdf($path);

            $this->logger->event('PARSE_FALLBACK', Logger::STATUS_SKIP, [
                'url' => $url,
                'jenis' => 'dokumen',
                'sebab' => (string) $reason,
                'pindai' => $pindai ? 'ya' : 'tidak',
            ], $pindai
                ? 'Berkas dokumen tampak hasil PINDAI/gambar (tanpa lapisan teks); isinya hanya bisa dibaca dengan OCR pada layanan /parse'
                : 'Parser lokal hanya untuk HTML; berkas dokumen (pdf/docx/xlsx) harus diparse oleh layanan /parse');

            return ['', '', 'service'];
        }

        if (!$this->localFallbackEnabled) {
            $this->logger->event('PARSE_FALLBACK', Logger::STATUS_SKIP, ['url' => $url], 'Parser lokal dimatikan (parse_fallback.local_markdown=false)');

            return ['', '', 'service'];
        }

        $this->logger->event('PARSE_FALLBACK', Logger::STATUS_FALLBACK, ['sebab' => (string) $reason], 'Beralih ke parser HTML -> Markdown lokal');

        $extracted = $this->fallbackParser->extract($this->fetcher->read($path));

        if (trim($extracted['markdown']) === '') {
            $this->logger->after('PARSE_AFTER', Logger::STATUS_FAIL, ['mode' => 'lokal'], 'Parser lokal juga tidak menghasilkan markdown');

            return ['', '', 'lokal'];
        }

        $this->result->parseFallback++;
        $stats['parse_fallback']++;
        $this->logger->after('PARSE_AFTER', Logger::STATUS_FALLBACK, [
            'mode' => 'lokal',
            'judul' => $extracted['title'],
            'markdown_karakter' => mb_strlen($extracted['markdown']),
        ], 'Markdown dihasilkan parser lokal');

        return [$extracted['markdown'], $extracted['title'], 'lokal'];
    }

    /**
     * Kelompokkan chunk menjadi batch untuk /embed.
     *
     * Dua batas dipakai bersamaan: jumlah chunk (site->embedBatchSize) dan total
     * karakter (config embed.max_chars_per_batch). Batas karakter penting setelah
     * ukuran chunk dinaikkan menjadi 12000 karakter: tanpa batas itu satu
     * permintaan /embed dapat memuat puluhan ribu karakter sehingga kehabisan
     * waktu pada service embedding lokal.
     *
     * Satu chunk selalu masuk ke satu batch walau lebih panjang dari batas
     * karakter, supaya tidak ada chunk yang tidak pernah di-embed.
     *
     * @param list<Chunk> $chunks
     *
     * @return list<list<Chunk>>
     */
    private static function batchChunks(array $chunks, int $maxChunks, int $maxChars): array
    {
        $batches = [];
        $batch = [];
        $chars = 0;

        foreach ($chunks as $chunk) {
            $length = $chunk->charCount();

            if ($batch !== []
                && (count($batch) >= $maxChunks || ($maxChars > 0 && $chars + $length > $maxChars))) {
                $batches[] = $batch;
                $batch = [];
                $chars = 0;
            }

            $batch[] = $chunk;
            $chars += $length;
        }

        if ($batch !== []) {
            $batches[] = $batch;
        }

        return $batches;
    }

    /**
     * Cek apakah berkas PDF tampak hasil PINDAI/gambar: berisi objek gambar
     * tetapi tidak punya font sama sekali sehingga tidak ada teks yang bisa
     * diekstrak. Dipakai HANYA untuk memberi keterangan pada log mengapa
     * markdown hasil /parse kosong (butuh OCR), bukan untuk mengubah alur.
     */
    private static function looksLikeScannedPdf(string $path): bool
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'pdf' || !is_file($path)) {
            return false;
        }

        $isi = (string) @file_get_contents($path, false, null, 0, 300_000);

        return $isi !== ''
            && !str_contains($isi, '/Font')
            && (str_contains($isi, '/Image') || str_contains($isi, '/DCTDecode'));
    }

    /**
     * Ambil tautan baru dari HTML (hanya bila crawling berantai aktif).
     *
     * @return list<string>
     */
    private function extractLinks(SiteConfig $site, string $html, string $baseUrl, int $depth, int $maxDepth): array
    {
        if ($html === '' || !$this->followLinksRun || $depth >= $maxDepth) {
            return [];
        }

        $found = LinkExtractor::extract($html, $baseUrl, $this->skipExtensions());
        $allowed = [];
        foreach ($found as $link) {
            if ($site->allowsUrl($link)) {
                $allowed[] = $link;
            }
        }

        $this->logger->info('LINK_SCAN', 'Tautan diekstrak dari halaman', [
            'ditemukan' => count($found),
            'kandidat' => count($allowed),
            'diabaikan' => count($found) - count($allowed),
        ]);

        return $allowed;
    }

    /**
     * Temukan berkas dokumen dari log jaringan (NetLog) halaman yang baru
     * dirender, tanpa aturan per situs.
     *
     * Halaman yang tautan unduhannya dibuat JavaScript (mis. daftar PDF di
     * /informasi-publik) memanggil API-nya sendiri saat dirender; NetLog mencatat
     * permintaan itu sehingga crawler bisa memanggil ulang API tersebut (GET) dan
     * mengunduh berkas dokumen yang ditemukan di dalamnya. URL hasilnya
     * didaftarkan sebagai DOKUMEN (lihat isDocumentUrl()) sehingga tetap diproses
     * walau host berkasnya berbeda dari halaman.
     *
     * @return array<string, string> url => judul (kosong bila tidak ada)
     */
    private function harvestDocuments(SiteConfig $site, string $pageUrl, bool $diRender): array
    {
        $netLog = $this->renderer->netLogPath();

        // Halaman ini tidak dirender (mis. HTML statis / render dimatikan): log
        // jaringan yang masih tersimpan milik halaman SEBELUMNYA, jadi tidak
        // boleh dipakai -- berkasnya dibuang supaya tidak membingungkan.
        if (!$diRender || !$site->autoDocuments || $netLog === null) {
            $this->renderer->forgetNetLog();

            return [];
        }

        // Penemuan dokumen membaca JSON API pihak ketiga yang bentuknya bebas,
        // jadi galat di sini (mis. TypeError/ValueError saat menelusuri JSON)
        // TIDAK boleh mematikan seluruh run: dicatat sebagai DOC_AUTO status
        // GAGAL, lalu halaman tetap diproses seperti biasa (HTML/DOM-nya sudah
        // dirender dan siap dikirim ke /parse).
        try {
            $hasil = $this->harvest->harvest($site, $pageUrl, $netLog, $this->autoDocumentsMax, $this->autoDocumentsApis);
        } catch (Throwable $exception) {
            $this->harvestFailed($site, $pageUrl, $exception);

            return [];
        }

        // Log jaringan (bisa >2 MB per halaman) sudah dibaca: berkas sementara
        // di folder temp langsung dibuang supaya tidak menumpuk.
        $this->renderer->forgetNetLog();

        foreach ($hasil['laporan'] as $laporan) {
            $dapat = $laporan['galat'] === '' && $laporan['berkas'] > 0;

            $this->logger->event('DOC_AUTO', $dapat ? Logger::STATUS_OK : Logger::STATUS_SKIP, [
                'endpoint' => $laporan['endpoint'],
                'http' => $laporan['http'],
                'berkas' => $laporan['berkas'],
            ], $dapat
                ? 'Daftar berkas dokumen ditemukan dari API yang dipanggil halaman'
                : 'API yang dipanggil halaman tidak memberi berkas dokumen'
                    . ($laporan['galat'] === '' ? '' : ' (' . $laporan['galat'] . ')'));
        }

        if ($hasil['berkas'] === []) {
            return [];
        }

        // URL ini SELALU diproses sebagai dokumen (boleh beda host dari halaman)
        // dan judul dari API dipakai sebagai judul payload vektor.
        foreach ($hasil['berkas'] as $url => $judul) {
            $this->documentApiUrls[$site->id][$url] = $judul;
        }

        $this->logger->info('DOC_AUTO_QUEUE', 'Berkas dokumen hasil penemuan otomatis masuk antrean (diproses sebagai DOKUMEN)', [
            'halaman' => Text::oneLine($pageUrl, 120),
            'berkas' => count($hasil['berkas']),
            'contoh' => implode(', ', array_slice(array_keys($hasil['berkas']), 0, 3)),
        ]);

        return $hasil['berkas'];
    }

    /**
     * Catat kegagalan penemuan dokumen otomatis untuk satu halaman.
     *
     * Dipanggil dari harvestDocuments() saat penelusuran JSON API halaman
     * melempar galat: run TIDAK dihentikan (berbeda dengan galat tak tertangani
     * yang dulu membatalkan seluruh job), hanya berkas tambahan dari API
     * halaman itu yang tidak didapat. Log-nya memuat jenis galat + pesannya
     * sehingga penyebabnya masih bisa ditelusuri (mis. TypeError saat membaca
     * bentuk JSON baru).
     */
    private function harvestFailed(SiteConfig $site, string $pageUrl, Throwable $exception): void
    {
        // NetLog sudah dibaca habis: berkas sementara dibuang seperti jalur normal.
        $this->renderer->forgetNetLog();

        $this->result->autoDocumentsFailed++;
        $stat = &$this->result->site($site->id);
        $stat['dokumen_auto_gagal'] = (int) ($stat['dokumen_auto_gagal'] ?? 0) + 1;

        $this->logger->event('DOC_AUTO', Logger::STATUS_FAIL, [
            'halaman' => Text::oneLine($pageUrl, 120),
            'galat' => Text::oneLine($exception->getMessage(), 200),
            'jenis_galat' => $exception::class,
        ], 'Penemuan dokumen otomatis dari API halaman gagal; halaman tetap diproses dan run dilanjutkan');
    }

    /**
     * Titik aman untuk JEDA job: bila dashboard meminta jeda, berhenti di sini
     * (tanpa membatalkan apa pun) dan kembali setelah perintah lanjutkan.
     *
     * Dipanggil hanya di batas pekerjaan (antar batch halaman / antar site)
     * sehingga tidak ada unduhan maupun pengiriman ke /parse//embed yang
     * terpotong di tengah jalan.
     */
    private function pauseCheckpoint(string $titik, string $siteId = ''): void
    {
        if ($this->control === null || !$this->control->enabled() || !$this->control->paused()) {
            return;
        }

        $this->logger->event('JOB_PAUSE', Logger::STATUS_PROGRESS, [
            'titik' => $titik,
            'site' => $siteId === '' ? '-' : $siteId,
            'bendera' => $this->control->flagPath() === null ? '-' : basename((string) $this->control->flagPath()),
        ], 'Job dijeda dari dashboard: proses berhenti di titik aman ini dan menunggu perintah lanjutkan');

        $detik = $this->control->waitUntilResumed();

        $this->result->pauseCount++;
        $this->result->pausedSeconds += $detik;

        $this->logger->event('JOB_RESUME', Logger::STATUS_OK, [
            'titik' => $titik,
            'durasi_jeda' => Text::duration($detik),
        ], 'Job dilanjutkan; crawl meneruskan antrean dari titik yang sama');
    }

    /**
     * Ambil daftar BERKAS DOKUMEN dari API situs (aturan "document_api") lalu
     * daftarkan URL-nya pada antrean crawl.
     *
     * Kegunaan: situs yang menyembunyikan tautan unduhan di balik JavaScript
     * (halaman hanya memuat judul bab, daftar berkas baru diminta browser lewat
     * API JSON) tidak bisa ditelusuri lewat tautan HTML. Aturan "document_api"
     * pada config/sites.json memberi endpoint + nama field berkasnya, sehingga
     * berkasnya ikut diunduh -> /parse -> /embed -> Qdrant.
     *
     * robots.txt host BERKAS (bisa berbeda dari host situs) tetap dihormati.
     *
     * @param list<array{url: string, depth: int}> $queue
     * @param array<string, bool>                  $visited
     * @param array<string, mixed>                 $stats
     */
    private function queueDocumentApi(
        SiteConfig $site,
        array &$queue,
        array &$visited,
        bool $respectRobots,
        array &$stats,
    ): void {
        if (!$this->documentApi->hasRules($site)) {
            return;
        }

        $hasil = $this->documentApi->collect($site);

        foreach ($hasil['laporan'] as $laporan) {
            // Permintaan metadata ke API situs tetap dihitung pada kuota run
            // supaya batas permintaan tetap menggambarkan beban ke situs.
            $this->requestsUsed++;

            $dapat = $laporan['galat'] === '' && $laporan['jumlah'] > 0;

            $this->logger->event('DOC_API', $dapat ? Logger::STATUS_OK : Logger::STATUS_SKIP, [
                'endpoint' => $laporan['endpoint'],
                'http' => $laporan['http'],
                'berkas' => $laporan['jumlah'],
            ], $dapat
                ? 'Daftar berkas dokumen diterima dari API situs'
                : 'Daftar berkas dokumen tidak didapat dari API situs'
                    . ($laporan['galat'] === '' ? ' (tidak ada berkas yang cocok)' : ' (' . $laporan['galat'] . ')'));
        }

        if ($hasil['berkas'] === []) {
            return;
        }

        $robots = $this->documentRobots($respectRobots, array_keys($hasil['berkas']));
        $this->documentApiUrls[$site->id] = $hasil['berkas'];

        $ditambah = 0;
        $ditolakRobots = 0;

        foreach ($hasil['berkas'] as $url => $judul) {
            $aturanHost = $robots[Text::hostname($url)] ?? null;

            if ($aturanHost !== null && !$aturanHost->allows($url)) {
                $ditolakRobots++;
                $this->result->urlsSkipped++;
                $stats['url_dilewati']++;

                $this->logger->event('DOC_API_SKIP', Logger::STATUS_SKIP, [
                    'url' => $url,
                    'robots' => $aturanHost->url,
                ], 'Berkas dari API situs dilarang robots.txt host-nya');

                continue;
            }

            if (isset($visited[$url])) {
                continue;
            }

            $visited[$url] = true;
            $queue[] = ['url' => $url, 'depth' => 1];
            $this->result->urlsQueued++;
            $ditambah++;
        }

        $this->logger->info('DOC_API_QUEUE', 'Berkas dokumen dari API situs masuk antrean (diproses sebagai DOKUMEN)', [
            'berkas' => $ditambah,
            'dilewati_robots' => $ditolakRobots,
            'antrean' => count($queue),
            'contoh' => implode(', ', array_slice(array_keys($hasil['berkas']), 0, 3)),
        ]);
    }

    /**
     * robots.txt setiap host berkas dokumen (satu site bisa punya beberapa host
     * berkas). Null bila pemeriksaan robots dimatikan.
     *
     * @param list<string> $urls
     *
     * @return array<string, RobotsTxt>
     */
    private function documentRobots(bool $respect, array $urls): array
    {
        if (!$respect) {
            return [];
        }

        $aturan = [];

        foreach ($urls as $url) {
            $host = Text::hostname($url);

            if ($host === '' || isset($aturan[$host])) {
                continue;
            }

            $skema = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? 'https'));
            $aturan[$host] = $this->robotsForHost($host, $skema === 'http' ? 'http' : 'https');
        }

        return $aturan;
    }

    /**
     * Muat (sekali per host) dan periksa robots.txt host di luar site - dipakai
     * untuk host berkas dokumen dari aturan "document_api".
     */
    private function robotsForHost(string $host, string $scheme): RobotsTxt
    {
        $key = $scheme . '|' . $host;

        if (!isset($this->documentRobotsCache[$key])) {
            $this->documentRobotsCache[$key] = RobotsTxt::loadFor(
                $this->http,
                $host,
                $scheme,
                $this->crawlConfig['user_agent'] ?? 'crawler-embed/1.0'
            );
        }

        return $this->documentRobotsCache[$key];
    }

    /**
     * Muat (sekali per site) dan periksa robots.txt.
     */
    private function robotsFor(SiteConfig $site, bool $respect): RobotsTxt
    {
        if (!$respect) {
            return RobotsTxt::missing('', 0, 'pemeriksaan robots.txt dimatikan');
        }

        $key = $site->id . '|' . ($site->hosts()[0] ?? '');
        if (!isset($this->robotsCache[$key])) {
            $this->robotsCache[$key] = RobotsTxt::load(
                $this->http,
                $site,
                $site->userAgent ?? (string) ($this->crawlConfig['user_agent'] ?? 'crawler-embed/1.0')
            );
        }

        return $this->robotsCache[$key];
    }

    /**
     * Persentase pengurangan panjang teks untuk baris log (mis. "62%").
     */
    private static function persenBerkurang(int $sebelum, int $sesudah): string
    {
        if ($sebelum <= 0 || $sesudah >= $sebelum) {
            return '0%';
        }

        return ((int) round((1 - $sesudah / $sebelum) * 100)) . '%';
    }

    /**
     * Tentukan judul dokumen yang dipakai payload vektor, baris "# <judul>"
     * pada berkas markdown, dan log.
     *
     * /parse memakai nama berkas yang diunggah sebagai "title" sehingga nilai
     * itu bukan judul halaman; karena itu urutan pemilihannya:
     *
     *   1. <title> HTML yang diunduh (paling terbaca, mis. "Kementerian Dalam
     *      Negeri Republik Indonesia") — tetapi bila judul itu sama dengan judul
     *      halaman pertama site (berarti judul SITUS, bukan judul halaman),
     *      judul dilewati dan heading markdown dipakai;
     *   2. heading pertama markdown hasil /parse (untuk PDF: "Document");
     *   3. judul dari /parse bila memang bukan nama berkas;
     *   4. segmen URL -> host (titleFromUrl()).
     *
     * @param string $parseTitle judul dari /parse (biasanya basename berkas)
     * @param string $markdown   markdown hasil /parse
     * @param string $html       HTML mentah (kosong untuk berkas dokumen)
     * @param string $rawFile    path berkas mentah (basename-nya ditolak)
     * @param string $preferred  judul dari API situs (aturan "content_api"),
     *                           paling dipercaya karena berasal dari data halaman
     */
    private function resolveTitle(
        SiteConfig $site,
        string $parseTitle,
        string $markdown,
        string $html,
        string $url,
        string $rawFile,
        string $preferred = ''
    ): string {
        $htmlTitle = $this->htmlTitle($html);

        // Judul <title> yang sama pada banyak halaman hanyalah judul SITUS
        // (mis. "Kementerian Dalam Negeri Republik Indonesia" pada semua halaman
        // situs Next.js). Halaman pertama yang diproses dipakai sebagai acuan;
        // halaman lain yang judulnya sama memakai heading halamannya sendiri
        // supaya payload.title tetap membedakan tiap dokumen.
        if ($htmlTitle !== '') {
            $judulSitus = $this->siteTitles[$site->id] ?? null;

            if ($judulSitus === null) {
                $this->siteTitles[$site->id] = $htmlTitle;
            } elseif ($htmlTitle === $judulSitus && $this->markdownHeading($markdown) !== '') {
                $this->logger->info('TITLE', 'Judul HTML sama untuk semua halaman (judul situs); heading halaman dipakai', [
                    'judul_situs' => Text::oneLine($htmlTitle, 120),
                ]);

                $htmlTitle = '';
            }
        }

        $kandidat = [
            'api_title' => trim($preferred),
            'html_title' => $htmlTitle,
            'markdown_heading' => $this->markdownHeading($markdown),
            'parse_title' => trim($parseTitle),
        ];

        foreach ($kandidat as $sumber => $nilai) {
            // Nama berkas dan judul teknis ("file", "dokumen", "download")
            // bukan judul yang bisa dibaca: lanjut ke kandidat berikutnya.
            if ($nilai === '' || self::looksLikeFileName($nilai, $rawFile) || self::looksLikeGenericTitle($nilai)) {
                continue;
            }

            $this->logger->info('TITLE', 'Judul dokumen ditentukan', [
                'sumber' => $sumber,
                'judul' => Text::oneLine($nilai, 120),
            ]);

            return $nilai;
        }

        return $this->titleFromUrl($url);
    }

    /**
     * Isi tag <title> halaman HTML (entitas & spasi dirapikan).
     */
    private function htmlTitle(string $html): string
    {
        if ($html === '' || preg_match('/<title[^>]*>(.*?)<\/title>/isu', $html, $match) !== 1) {
            return '';
        }

        $title = html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
    }

    /**
     * Heading pertama markdown hasil /parse (mis. "# Document" pada PDF).
     */
    private function markdownHeading(string $markdown): string
    {
        if (preg_match('/^\s{0,3}#{1,6}\s+(.+)$/mu', $markdown, $match) !== 1) {
            return '';
        }

        return trim(preg_replace('/[#*_`]+/u', '', $match[1]) ?? $match[1]);
    }

    /**
     * Apakah teks terlihat seperti nama berkas hasil unduhan (nama berkas
     * mentah crawler, mis. "www-kemendagri-go-id-beranda--0d19ccff.html" atau
     * "_ppid.pdf"), bukan judul halaman. Nilai semacam ini tidak dipakai sebagai
     * payload.title.
     */
    private static function looksLikeFileName(string $text, string $rawFile): bool
    {
        if ($rawFile !== '' && $text === basename($rawFile)) {
            return true;
        }

        if (preg_match('/\.(html?|xhtml|pdf|docx?|xlsx?|pptx?|csv|rtf|txt|odt|ods|xml|json)$/i', $text) === 1) {
            return true;
        }

        // Slug hasil Text::slug(): "host-halaman--hash" tanpa spasi.
        return preg_match('/\s/u', $text) !== 1
            && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+){2,}$/i', $text) === 1;
    }

    /**
     * Judul generik/teknis yang BUKAN nama dokumen: jenis berkas atau label
     * tombol ("file", "dokumen", "download"), nilai boolean, dan angka saja.
     *
     * Nilai seperti ini sering datang dari API halaman (mis. {"type":"file"})
     * atau dari field penanda sehingga payload vektor bisa berjudul "file"
     * walaupun dokumennya bernama lain; judul seperti itu dilewati supaya
     * kandidat berikutnya (heading markdown / judul hasil /parse) dipakai.
     */
    private static function looksLikeGenericTitle(string $text): bool
    {
        $lower = mb_strtolower(trim($text));

        if ($lower === '') {
            return true;
        }

        // Angka saja (mis. id/tahun tanpa keterangan) tidak informatif.
        if (preg_match('/^\d+$/u', $lower) === 1) {
            return true;
        }

        return in_array($lower, self::GENERIC_TITLES, true);
    }

    /**
     * Judul cadangan dari URL bila /parse dan parser lokal sama-sama tidak
     * menemukan <title>.
     */
    private function titleFromUrl(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $label = trim(str_replace(['-', '_', '/'], ' ', $path));

        return $label === '' ? Text::hostname($url) : ucwords($label);
    }

    /**
     * Ekstensi yang tidak di-crawl (gambar/aset/arsip statis). Dapat ditimpa
     * lewat config/app.php -> crawl.skip_extensions.
     *
     * Bila crawl.follow_document_links=true, ekstensi dokumen
     * (SiteConfig::DOCUMENT_EXTENSIONS: pdf/docx/xlsx/...) DIBUANG dari daftar
     * ini supaya tautan lampiran pada halaman web ikut ditemukan; URL-nya lalu
     * diproses dalam mode dokumen (lihat isDocumentUrl()).
     *
     * @return list<string>
     */
    private function skipExtensions(): array
    {
        $configured = $this->crawlConfig['skip_extensions'] ?? null;

        if (is_array($configured) && $configured !== []) {
            $skip = [];
            foreach ($configured as $item) {
                $skip[] = strtolower(ltrim((string) $item, '.'));
            }
        } else {
            $skip = [
                'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods',
                'zip', 'rar', '7z', 'gz', 'tar', 'apk', 'exe', 'iso',
                'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico',
                'mp4', 'mp3', 'wav', 'avi', 'mov',
                'css', 'js', 'json', 'xml', 'rss', 'woff', 'woff2', 'ttf', 'eot',
            ];
        }

        // Tautan dokumen pada halaman web: ekstensi dokumen justru yang dicari,
        // jadi dikeluarkan dari daftar lewati (mis. .pdf tidak dilewati lagi).
        if ($this->followDocumentLinks) {
            $skip = array_values(array_diff($skip, SiteConfig::DOCUMENT_EXTENSIONS));
        }

        return $skip;
    }
}
