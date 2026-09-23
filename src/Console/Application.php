<?php

declare(strict_types=1);

namespace App\Console;

use App\Crawl\HeadlessRenderer;
use App\Crawl\SiteConfig;
use App\Crawl\SiteRepository;
use App\Http\HttpClient;
use App\Pipeline\PendingDocuments;
use App\Pipeline\Pipeline;
use App\Pipeline\RunResult;
use App\Pipeline\VectorStore;
use App\Qdrant\QdrantClient;
use App\Qdrant\QdrantPush;
use App\Report\CrawlEstimator;
use App\Storage\CrawlSiteStore;
use App\Service\CrawlJobService;
use App\Service\IngestServiceClient;
use App\Support\Config;
use App\Support\JobControl;
use App\Support\JobStore;
use App\Support\Logger;
use App\Support\Text;
use Throwable;

/**
 * CLI crawler (`php bin/crawl.php <perintah>`).
 *
 * Perintah:
 *   health                      cek /health service worker-ingest
 *   list                        tampilkan daftar web pada config/sites.json
 *   crawl [site ...] [opsi]     jalankan crawl -> parse -> embed
 *   documents [site ...]        proses ULANG berkas dokumen di storage/documents -> Qdrant
 *                               (PDF yang dulu gagal diparse / tidak sempat diproses)
 *   retry --job=<id>            jalankan ULANG job dengan site + opsi yang sama
 *   jobs [--limit=N]            daftar job terakhir (status, jeda, pengulangan)
 *   temp [--umur=<jam>]         bersihkan sisa folder profil browser di temp
 *   qdrant [opsi]               unggah/ekspor berkas vektor ke Qdrant
 *   logs [opsi]                 tampilkan lokasi & isi log txt terakhir
 *   help                        bantuan
 */
final class Application
{
    /** @param array<string, mixed> $config isi config/app.php */
    public function __construct(private array $config)
    {
    }

    /**
     * @param list<string> $argv argumen mentah (termasuk nama script)
     */
    public function run(array $argv): int
    {
        $command = strtolower((string) ($argv[1] ?? 'help'));
        $args = array_slice($argv, 2);

        try {
            return match ($command) {
                'health', 'cek' => $this->health(),
                'list', 'sites', 'daftar' => $this->listSites(),
                'crawl', 'jalan', 'run' => $this->crawl($args),
                'documents', 'dokumen', 'dokumen-ulang', 'reprocess' => $this->documents($args),
                'estimate', 'estimasi', 'perkiraan' => $this->estimate($args),
                'retry', 'ulangi', 'ulang-job' => $this->retry($args),
                'qdrant', 'push-qdrant', 'vektor' => $this->qdrant($args),
                'logs', 'log' => $this->logs($args),
                'jobs', 'job' => $this->jobs($args),
                'temp', 'bersih-temp', 'cleanup' => $this->cleanTemp($args),
                'help', '-h', '--help' => $this->help(),
                default => $this->unknown($command),
            };
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return 1;
        }
    }

    private function health(): int
    {
        $service = $this->service();

        $this->title('HEALTH ' . $service->baseUrl());
        $result = $service->health();

        if (!$result->ok) {
            $this->error('Service tidak sehat: ' . ($result->error ?? 'tidak diketahui'));
            $this->line('Perintah saran: jalankan worker-ingest di ' . $service->baseUrl());

            return 1;
        }

        $this->line('status      : ' . ($result->status ?? '-'));
        $this->line('embedding   : ' . ($result->embedding ?? '-'));
        $this->line('parse path  : ' . $service->parseUrl());
        $this->line('embed path  : ' . $service->embedUrl());

        return 0;
    }

    private function listSites(): int
    {
        $repository = new SiteRepository();
        $sites = $repository->all();

        if ($sites === []) {
            $this->warn('Belum ada site pada config/sites.json');

            return 1;
        }

        $this->title('DAFTAR TARGET (' . count($sites) . ')');

        foreach ($sites as $site) {
            $this->line(sprintf(
                '%-24s %-8s %-8s halaman=%-12s kedalaman=%-12s jeda=%-6d tautan=%-5s %s',
                $site->id,
                $site->enabled ? '[AKTIF]' : '[MATI]',
                $site->documentMode ? 'dokumen' : 'web',
                $site->maxPages === 0 ? 'tanpa batas' : (string) $site->maxPages,
                $site->maxDepth < 0 ? 'tanpa batas' : (string) $site->maxDepth,
                $site->rateLimitMs,
                $site->followLinks ? 'ya' : 'tidak',
                $site->name
            ));
            $this->line('  url   : ' . implode(', ', $site->startUrls));

            $mode = (string) ($site->jsRender['mode'] ?? 'auto');
            $this->line('  render: ' . ($mode === 'off'
                ? 'off (HTML hasil unduhan apa adanya)'
                : $mode . ' (halaman kerangka JavaScript dirender browser headless, tanpa API)'
                    . (isset($site->jsRender['wait_ms']) ? ' tunggu=' . (int) $site->jsRender['wait_ms'] . 'ms' : '')));

            if (!$site->documentMode) {
                $ikutiDokumen = (bool) ($this->config['crawl']['follow_document_links'] ?? false);
                $this->line('  dokumen: ' . ($ikutiDokumen
                    ? 'tautan .pdf/.docx pada halaman ini IKUT diunduh & diparse'
                    : 'tautan .pdf/.docx dilewati (CRAWLER_FOLLOW_DOCUMENT_LINKS=true untuk mengikuti)'));
                $this->line('  situs lain: ' . ($site->followExternal === 'off'
                    ? 'tidak dijelajahi ("follow_external": "family"/"all" untuk mengikuti)'
                    : $site->followExternal . ' (host lain dalam keluarga domain yang sama / semua host)'));
            }

            if ($site->contentApi !== []) {
                $this->line('  konten: ' . count($site->contentApi) . ' aturan API situs (cadangan bila render gagal)');
            }

            if ($site->documentApi !== []) {
                $this->line('  berkas: ' . count($site->documentApi) . ' aturan API dokumen (tautan unduhan dari JSON situs)');
            }

            if ($site->dropDuplicateContent) {
                $this->line('  duplikat: isi identik/mirip dibuang sekali kirim (batas kemiripan '
                    . number_format($site->duplicateSimilarity, 2, '.', '') . ')');
            }
        }

        return 0;
    }

    /**
     * Jalankan crawl -> parse -> embed untuk site terpilih.
     *
     * @param list<string> $args
     */
    private function crawl(array $args): int
    {
        $repository = new SiteRepository();
        $parsed = $this->parseOptions($args);

        $jobs = new JobStore($this->config['paths'] ?? []);
        $jobId = $parsed['job'];

        if ($jobId !== null && $jobs->get($jobId) === null) {
            $jobs->create($parsed['sites'], $parsed['run'], $jobId);
        }

        $sites = [];
        try {
            $sites = $parsed['sites'] === []
                ? $repository->enabled()
                : $repository->findMany($parsed['sites']);
        } catch (Throwable $exception) {
            // Nama site salah tulis pada --site=...: galat ini terjadi SEBELUM
            // pipeline berjalan sehingga job harus tetap ditandai GAGAL (kalau
            // tidak, job tampak MENUNGGU selamanya sampai UI merekonsiliasi).
            // Pengulangan otomatis TIDAK dijalankan: kesalahan konfigurasi tidak
            // akan berubah hanya dengan mengulang.
            if ($jobId !== null) {
                $jobs->markFailed($jobId, $exception->getMessage());
                $this->line('  perbaiki nama site pada opsi --site, lalu ulangi manual:');
                $this->line('  php bin/crawl.php retry --job=' . $jobId);
            }

            throw $exception;
        }

        if ($sites === []) {
            $this->warn('Tidak ada site aktif untuk di-crawl. Periksa config/sites.json.');

            if ($jobId !== null) {
                $jobs->markFailed($jobId, 'Tidak ada site aktif pada config/sites.json.');
            }

            return 1;
        }

        // Anti-duplikat: website yang SEMUA start_urls-nya sudah pernah sukses
        // (tersimpan di MySQL) dilewati, kecuali run ini memakai --re-crawl.
        $crawlSettings = $this->config['crawl'] ?? [];
        $mysqlRecord = (bool) ($crawlSettings['mysql_record'] ?? false);
        $skipSuccess = (bool) ($crawlSettings['mysql_skip_success'] ?? false);
        $rekrawal = (bool) ($parsed['run']['re_crawl'] ?? false);

        if ($mysqlRecord && $skipSuccess && !$rekrawal) {
            try {
                $store = new CrawlSiteStore($this->config['mysql'] ?? []);

                foreach ($sites as $index => $site) {
                    $sudah = $site->startUrls !== [];

                    foreach ($site->startUrls as $url) {
                        if (!$store->hasSuccess($url)) {
                            $sudah = false;
                            break;
                        }
                    }

                    if (!$sudah) {
                        continue;
                    }

                    unset($sites[$index]);
                    $this->line('  dilewati (sudah sukses sebelumnya): ' . $site->id . ' — pakai --re-crawl untuk memaksa');
                }

                $sites = array_values($sites);
            } catch (\Throwable $exception) {
                $this->warn('MySQL tidak bisa dipakai untuk anti-duplikat: ' . $exception->getMessage());
            }
        }

        if ($sites === []) {
            $this->warn('Semua site sudah pernah di-crawl sukses (lihat MySQL). Pakai --re-crawl untuk mengulang.');

            if ($jobId !== null) {
                $jobs->markFailed($jobId, 'Semua site sudah pernah di-crawl sukses.');
            }

            return 1;
        }

        $runId = $this->runId();
        $logger = $this->logger($runId);

        // Jatah pengulangan otomatis setelah galat fatal: opsi CLI (dikirim
        // CrawlJobService, tersimpan pada berkas job) menimpa nilai config.
        $autoRetry = max(0, (int) ($parsed['run']['auto_retry']
            ?? ($this->config['crawl']['auto_retry'] ?? 0)));

        // Pengendali jeda/lanjutkan dari dashboard hanya berlaku bila run ini
        // terikat pada sebuah job (berkas storage/jobs/<id>.pause).
        $control = $jobId === null
            ? JobControl::inactive()
            : new JobControl(
                $jobs->pauseFlagPath($jobId),
                static function (bool $dijeda, float $detik) use ($jobs, $jobId): void {
                    if ($dijeda) {
                        $jobs->markPaused($jobId, date('Y-m-d H:i:s'));

                        return;
                    }

                    $jobs->markResumed($jobId, $detik);
                },
                max(200, (int) ($this->config['crawl']['pause_poll_ms'] ?? 1000)),
            );

        $pipeline = new Pipeline($repository, $logger, $this->config, $control);

        if ($jobId !== null) {
            // Jatah pengulangan otomatis disimpan pada berkas job supaya
            // dashboard bisa menampilkannya dan job hasil pengulangan mewarisi
            // sisa jatahnya.
            $jobs->update($jobId, ['auto_retry' => $autoRetry]);
            $jobs->markRunning($jobId, $runId, $logger->runFile());
        }

        $this->title('CRAWL ' . $runId);
        foreach ($sites as $site) {
            $halaman = (int) ($parsed['run']['max_pages'] ?? $site->maxPages);

            $kedalamanRun = (int) ($parsed['run']['max_depth'] ?? $site->maxDepth);

            $this->line(sprintf(
                '  - %-24s %s  (jenis=%s, halaman=%s, kedalaman=%s)',
                $site->id,
                implode(', ', $site->startUrls),
                $site->documentMode ? 'dokumen' : 'web',
                $halaman === 0 ? 'tanpa batas' : (string) $halaman,
                $kedalamanRun < 0 ? 'tanpa batas' : (string) $kedalamanRun
            ));
        }

        $crawlConfig = $this->config['crawl'] ?? [];
        $batasPermintaan = (int) ($parsed['run']['max_requests'] ?? $crawlConfig['max_requests_per_crawl'] ?? 0);
        $konkurensi = max(1, (int) ($parsed['run']['concurrency'] ?? $crawlConfig['concurrency'] ?? 1));

        $this->line('  batas permintaan/run : ' . ($batasPermintaan === 0 ? 'tanpa batas' : (string) $batasPermintaan));
        $this->line('  konkurensi unduh     : ' . $konkurensi . ' halaman sekaligus');

        // Halaman berbasis JavaScript: crawler memakai browser headless yang ada
        // di mesin supaya isi halaman benar-benar terbaca (tanpa aturan API).
        $renderProbe = new HeadlessRenderer($crawlConfig);
        $renderMode = HeadlessRenderer::modeValue($parsed['run']['render'] ?? ($crawlConfig['js_render'] ?? 'auto')) ?? 'auto';
        $this->line('  render halaman JS    : ' . $renderMode . ($renderMode === 'off'
            ? ' (HTML hasil unduhan apa adanya)'
            : ($renderProbe->available()
                ? ' -> browser: ' . basename((string) $renderProbe->bin())
                : ' -> browser headless TIDAK ditemukan: ' . $renderProbe->lastError())));

        $pushQdrant = (bool) ($parsed['run']['push_qdrant'] ?? ($this->config['qdrant']['push_after_crawl'] ?? true));
        $this->line('  push otomatis Qdrant : ' . ($pushQdrant
            ? 'ya -> ' . rtrim((string) ($this->config['qdrant']['base_url'] ?? 'http://localhost:6333'), '/')
                . ' / ' . (string) ($this->config['qdrant']['collection'] ?? 'documents')
            : 'tidak (pakai --push-qdrant untuk mengaktifkan pada run ini)'));

        $crawlSettings = $this->config['crawl'] ?? [];
        $ikutiSitusLain = SiteConfig::followExternalMode(
            $parsed['run']['follow_external'] ?? ($crawlSettings['follow_external'] ?? 'family')
        );
        $this->line('  jelajah situs lain   : ' . ($ikutiSitusLain === 'off'
            ? 'tidak (hanya host pada URL target)'
            : $ikutiSitusLain . ' (maks ' . max(0, (int) ($crawlSettings['follow_external_max_hosts'] ?? 25)) . ' host lintas situs)'));

        $this->line('  log run: ' . $logger->runFile());
        $this->line('');
        $result = null;
        try {
            $result = $pipeline->run($sites, $parsed['run']);
        } catch (Throwable $exception) {
            if ($jobId !== null) {
                $jobs->markFailed($jobId, $exception->getMessage());
                $this->autoRetry($jobs, $jobId, $autoRetry, $exception->getMessage());
            }

            throw $exception;
        }

        // Catat hasil per website ke MySQL lokal: sukses -> "success" (anti
        // duplikat pada run berikutnya), gagal akses -> "failed_akses".
        if ($mysqlRecord) {
            try {
                $store = new CrawlSiteStore($this->config['mysql'] ?? []);
                $run = (string) ($result->runId !== '' ? $result->runId : $runId);

                foreach ($result->sites as $siteId => $stat) {
                    $situs = $repository->find((string) $siteId);

                    if ($situs === null) {
                        continue;
                    }

                    $diproses = (int) ($stat['url_diproses'] ?? 0);
                    $gagal = (int) ($stat['gagal_unduh'] ?? 0);
                    $status = $diproses > 0 && ($diproses - $gagal) === 0 ? 'failed_akses' : 'success';
                    $pesan = $status === 'failed_akses'
                        ? 'failed akses (' . $gagal . ' unduhan gagal dari ' . $diproses . ' percobaan)'
                        : 'crawl selesai: ' . (int) ($stat['dokumen'] ?? 0) . ' dokumen, ' . (int) ($stat['vektor'] ?? 0) . ' vektor';

                    foreach ($situs->startUrls as $url) {
                        $store->record(
                            \App\Crawl\DomainDiscovery::normalize($url),
                            $url,
                            [
                                'document_type' => $situs->documentType(),
                                'province' => $situs->metadataFor('province'),
                                'city' => $situs->metadataFor('city'),
                                'year' => $situs->metadataFor('year'),
                            ],
                            $status,
                            $pesan,
                            $run,
                        );
                    }

                    $this->line('  tercatat MySQL : ' . $siteId . ' -> ' . $status);
                }
            } catch (\Throwable $exception) {
                $this->warn('Gagal mencatat ke MySQL: ' . $exception->getMessage());
            }
        }

        if ($jobId !== null) {
            $jobs->markFinished($jobId, $result, $logger->runFile());
        }

        $this->summary($result);
        $this->line('  log run    : ' . $logger->runFile());
        $this->line('  log harian : ' . Config::path('logs', false) . DIRECTORY_SEPARATOR . 'crawl-' . date('Y-m-d') . '.txt');

        if ($jobId !== null) {
            $this->line('  job        : ' . $jobId . ' (' . (in_array($result->status, ['SUKSES', 'SUKSES_SEBAGIAN'], true) ? JobStore::STATUS_DONE : JobStore::STATUS_FAILED) . ')');
        }

        $this->line('');

        return in_array($result->status, ['SUKSES', 'SUKSES_SEBAGIAN'], true) ? 0 : 1;
    }

    /**
     * Ulangi job OTOMATIS setelah galat fatal (proses crawl mati di tengah run,
     * mis. TypeError yang tidak tertangani seperti RUN-20260920-075707).
     *
     * Job lama tetap berstatus GAGAL (riwayat + lognya utuh) dan job baru
     * dibuat dengan site + opsi yang sama, ditautkan lewat ulangi_ke/ulangi_dari.
     * Jatah (crawl.auto_retry / CRAWLER_AUTO_RETRY / --auto-retry=N) dikurangi
     * satu setiap pengulangan sehingga tidak bisa berputar tanpa henti; 0 =
     * tidak ada pengulangan otomatis (pakai tombol "Ulangi job" atau perintah
     * `php bin/crawl.php retry --job=<id>`).
     */
    private function autoRetry(JobStore $jobs, string $jobId, int $sisa, string $sebab): void
    {
        if ($sisa <= 0) {
            $this->line('  pengulangan otomatis: tidak aktif (auto_retry=0); ulangi manual dengan:');
            $this->line('                        php bin/crawl.php retry --job=' . $jobId);

            return;
        }

        $hasil = (new CrawlJobService($jobs, base_path()))->retry($jobId, $sisa - 1);

        if (!$hasil['ok']) {
            $this->warn('Job tidak bisa diulang otomatis: ' . $hasil['pesan']);

            return;
        }

        $this->line('  job diulang otomatis: ' . $hasil['job_id'] . ' (pid ' . $hasil['pid'] . ', sisa jatah '
            . max(0, $sisa - 1) . ') setelah galat: ' . Text::oneLine($sebab, 120));
    }

    /**
     * Tampilkan berkas yang akan diproses + yang tidak punya URL (mode --dry-run).
     *
     * @param list<array{url: string, path: string, berkas: string, sumber: string}> $items
     * @param list<string>                                                           $tanpaUrl
     */
    private function listPendingDocuments(array $items, array $tanpaUrl): void
    {
        foreach (array_slice($items, 0, 10) as $item) {
            $this->line('    [' . $item['sumber'] . '] ' . $item['berkas']
                . '  (' . Text::humanBytes((int) @filesize($item['path'])) . ')');
            $this->line('        -> ' . $item['url']);
        }

        if (count($items) > 10) {
            $this->line('    ... ' . (count($items) - 10) . ' berkas lain');
        }

        foreach (array_slice($tanpaUrl, 0, 10) as $path) {
            $this->warn('    tanpa URL: ' . basename($path) . ' (URL-nya tidak ditemukan pada markdown/HTML site)');
        }

        if (count($tanpaUrl) > 10) {
            $this->line('    ... ' . (count($tanpaUrl) - 10) . ' berkas tanpa URL lain');
        }
    }

    /**
     * Opsi perintah `documents`. Argumen tanpa awalan `--` diperlakukan sebagai
     * id site (sama seperti perintah crawl).
     *
     * @param list<string> $args
     *
     * @return array{sites: list<string>, urls: list<string>, limit: int, push_qdrant: bool|null, dry_run: bool}
     */
    private function parseDocumentsOptions(array $args): array
    {
        $options = ['sites' => [], 'urls' => [], 'limit' => 0, 'push_qdrant' => null, 'dry_run' => false];

        foreach ($args as $arg) {
            if (!str_starts_with($arg, '--')) {
                $options['sites'][] = $arg;

                continue;
            }

            $option = substr($arg, 2);
            $value = null;

            if (str_contains($option, '=')) {
                [$option, $value] = explode('=', $option, 2);
            }

            switch (strtolower(str_replace('-', '_', $option))) {
                case 'site':
                case 'sites':
                    foreach (explode(',', (string) $value) as $id) {
                        $id = trim($id);
                        if ($id !== '') {
                            $options['sites'][] = $id;
                        }
                    }
                    break;

                case 'url':
                case 'urls':
                    foreach (explode(',', (string) $value) as $url) {
                        $url = trim($url);
                        if ($url !== '') {
                            $options['urls'][] = $url;
                        }
                    }
                    break;

                case 'limit':
                case 'max':
                    $options['limit'] = max(0, (int) $value);
                    break;

                case 'push_qdrant':
                case 'push':
                    $options['push_qdrant'] = true;
                    break;

                case 'no_push_qdrant':
                case 'no_push':
                    $options['push_qdrant'] = false;
                    break;

                case 'dry_run':
                case 'dry':
                    $options['dry_run'] = true;
                    break;

                default:
                    $this->warn('Opsi tidak dikenal diabaikan: --' . $option);
            }
        }

        return $options;
    }

    /**
     * Kirim berkas vektor hasil crawl (`storage/vectors/<site>/<run-id>.jsonl`)
     * ke Qdrant; `--out` mengekspor payload NDJSON untuk diunggah manual dan
     * `--dry-run` hanya memvalidasi berkas tanpa menyentuh Qdrant.
     *
     * @param list<string> $args
     */
    /**
     * Proses ULANG berkas DOKUMEN yang sudah ada di storage/documents TANPA
     * crawl ulang: /parse -> chunk -> /embed -> Qdrant.
     *
     * Dipakai bila PDF tidak masuk Qdrant karena layanan /parse sedang MATI
     * saat crawl (semua berkas dokumen dilewati) atau karena run berhenti
     * (batas --max-pages / --max-requests) sebelum PDF pada halaman detail
     * sempat diunduh. URL asli tiap berkas dipulihkan dari document_id pada
     * namanya (lihat App\Pipeline\PendingDocuments), sehingga document_id, url,
     * province, city, dan year pada payload tetap sama seperti hasil crawl.
     *
     * @param list<string> $args
     */
    private function documents(array $args): int
    {
        $options = $this->parseDocumentsOptions($args);
        $repository = new SiteRepository();

        try {
            $sites = $options['sites'] === []
                ? $repository->enabled()
                : $repository->findMany($options['sites']);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return 1;
        }

        if ($sites === []) {
            $this->warn('Tidak ada site aktif untuk diproses. Periksa config/sites.json atau pakai --site=<id>.');

            return 1;
        }

        $dryRun = (bool) $options['dry_run'];
        $push = $options['push_qdrant'] ?? (bool) ($this->config['qdrant']['push_after_crawl'] ?? true);
        $limit = max(0, (int) $options['limit']);

        // Pemindai berkas: VectorStore dengan store_vectors dimatikan karena
        // perintah ini hanya membaca daftar berkas (tanpa menulis vektor).
        $finder = new PendingDocuments(new VectorStore($this->config['paths'] ?? [], false));
        $runId = 'DOK-' . date('Ymd-His');
        $logger = $dryRun ? null : $this->logger($runId);
        $pipeline = $logger === null ? null : new Pipeline($repository, $logger, $this->config);

        $this->title('DOKUMEN ' . ($dryRun ? 'UJI (--dry-run)' : $runId));
        $this->line('  mode   : ' . ($dryRun
            ? 'cari & tampilkan berkas saja (tidak ada parse/embed/push)'
            : 'parse -> chunk -> /embed' . ($push
                ? ' -> push Qdrant (' . (string) ($this->config['qdrant']['collection'] ?? 'documents') . ')'
                : ' (tanpa push Qdrant; pakai --push-qdrant untuk mengirim)')));
        $this->line('  sumber : berkas mentah di storage/documents/<site> yang belum ada vektornya');
        $this->line('  berkas : ' . ($limit > 0 ? 'maksimal ' . $limit . ' per site' : 'semua'));
        $this->line('');

        $totalVektor = 0;
        $totalGagal = 0;
        $totalTanpaUrl = 0;

        foreach ($sites as $site) {
            $pending = $finder->scan($site, $options['urls'], $limit);
            $totalTanpaUrl += count($pending['tanpa_url']);

            $this->line(sprintf(
                '  %-28s %d berkas dokumen, %d URL dipulihkan, %d tanpa URL',
                $site->id,
                $pending['berkas'],
                count($pending['items']),
                count($pending['tanpa_url'])
            ));

            if ($pending['dilewati_limit'] !== []) {
                $this->line('    ' . count($pending['dilewati_limit']) . ' berkas lain belum diproses '
                    . '(batas --limit=' . $limit . '); jalankan lagi tanpa --limit untuk memproses semuanya');
            }

            if ($dryRun) {
                $this->listPendingDocuments($pending['items'], $pending['tanpa_url']);

                continue;
            }

            if ($pending['items'] === []) {
                $this->warn('    tidak ada berkas yang bisa diproses pada site ini');

                if ($pending['tanpa_url'] !== []) {
                    $this->line('    ' . count($pending['tanpa_url']) . ' berkas tanpa URL asli; jalankan crawl ulang '
                        . 'URL-nya atau sebutkan manual dengan --url=<url>');
                }

                continue;
            }

            $hasil = $pipeline->resumeDocuments($site, $pending['items'], ['push_qdrant' => $push]);
            $totalVektor += (int) $hasil['vektor'];
            $totalGagal += (int) $hasil['gagal'];

            $this->line('    dokumen=' . $hasil['dokumen']
                . ' chunk/vektor=' . $hasil['vektor']
                . ' gagal=' . $hasil['gagal']
                . ' durasi=' . Text::duration((float) $hasil['durasi'])
                . ($hasil['berkas_vektor'] !== '' ? ' berkas=' . $hasil['berkas_vektor'] : ''));

            if (is_array($hasil['qdrant_push'])) {
                $this->line('    qdrant=' . $hasil['qdrant_push']['status']
                    . ' terkirim=' . $hasil['qdrant_push']['point_terkirim']
                    . ' gagal=' . $hasil['qdrant_push']['point_gagal']);
            }

            if ($pending['tanpa_url'] !== []) {
                $this->line('    ' . count($pending['tanpa_url']) . ' berkas tetap tanpa URL asli (perlu crawl ulang)');
            }
        }

        $this->line('');

        if ($dryRun) {
            $this->line('  Mode uji: tidak ada berkas yang diparse/di-embed. Jalankan tanpa --dry-run untuk memproses.');
            $this->line('  Contoh: php bin/crawl.php documents --site=' . $sites[0]->id . ' --push-qdrant');
            $this->line('');

            return 0;
        }

        $this->title('RINGKASAN');
        $this->line('  ' . str_pad('Vektor dibuat', 22, '.') . ' : ' . $totalVektor);
        $this->line('  ' . str_pad('Berkas gagal', 22, '.') . ' : ' . $totalGagal);
        $this->line('  ' . str_pad('Berkas tanpa URL', 22, '.') . ' : ' . $totalTanpaUrl);
        $this->line('  ' . str_pad('Log run', 22, '.') . ' : ' . ($logger !== null ? $logger->runFile() : '-'));

        if ($totalTanpaUrl > 0) {
            $this->warn('Berkas tanpa URL asli tidak bisa dibuatkan payload; crawl ulang halaman yang memuatnya.');
        }

        $this->line('');

        return $totalGagal === 0 ? 0 : 1;
    }

    /**
     * Estimasi lama crawl SEBELUM run (kalibrasi dari riwayat logs/runs/*.txt).
     *
     * Pemakaian:
     *   php bin/crawl.php estimate --halaman=200 --render=auto [site ...]
     *
     * @param list<string> $args
     */
    private function estimate(array $args): int
    {
        $repository = new SiteRepository();
        $ids = [];
        $halaman = 0;
        $modeRender = 'auto';

        foreach ($args as $arg) {
            if (!str_starts_with($arg, '--')) {
                $ids[] = $arg;

                continue;
            }

            $option = substr($arg, 2);
            $value = null;

            if (str_contains($option, '=')) {
                [$option, $value] = explode('=', $option, 2);
            }

            switch (strtolower(str_replace('-', '_', $option))) {
                case 'halaman':
                case 'pages':
                case 'max_pages':
                    $halaman = max(0, (int) $value);
                    break;

                case 'render':
                    $mode = HeadlessRenderer::modeValue($value) ?? 'auto';
                    $modeRender = $mode;
                    break;

                default:
                    $this->warn('Opsi tidak dikenal diabaikan: --' . $option);
            }
        }

        try {
            $sites = $ids === [] ? $repository->enabled() : $repository->findMany($ids);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return 1;
        }

        if ($sites === []) {
            $this->warn('Tidak ada site aktif. Periksa config/sites.json atau pakai --site=<id>.');

            return 1;
        }

        $estimator = new CrawlEstimator($this->config['paths'] ?? []);
        $hasil = $estimator->estimate($sites, ['halaman' => $halaman, 'mode_render' => $modeRender]);
        $total = $hasil['total'];

        $this->title('ESTIMASI LAMA CRAWL');
        $this->line('  kalibrasi   : ' . (int) $hasil['sampel'] . ' log run pada logs/runs/'
            . ($hasil['yakin'] ? '' : ' (masih sedikit, angka kasar)'));
        $this->line('  halaman/web : ' . ($halaman > 0 ? (string) $halaman . ' (dari opsi --halaman)' : 'rata-rata riwayat tiap site'));
        $this->line('  render      : ' . $modeRender);
        $this->line('');

        foreach ($hasil['baris'] as $row) {
            $this->line(sprintf(
                '  %-26s halaman=%-6d dokumen=%-5d vektor=%-6d %s (unduh %s + parse %s + render %s + embed %s) [%s]',
                (string) $row['site'],
                (int) $row['halaman'],
                (int) $row['dokumen'],
                (int) $row['vektor'],
                Text::duration((float) $row['detik_total']),
                Text::duration((float) $row['detik_unduh']),
                Text::duration((float) $row['detik_parse']),
                Text::duration((float) $row['detik_render']),
                Text::duration((float) $row['detik_embed']),
                (string) $row['sumber']
            ));
        }

        $this->line('');
        $this->line('  TOTAL       : ' . Text::duration((float) $total['detik_total'])
            . ' (' . $total['halaman'] . ' halaman, ' . $total['vektor'] . ' vektor)');
        $this->line('');

        foreach ($hasil['asumsi'] as $asumsi) {
            $this->line('  - ' . $asumsi);
        }

        $this->line('');

        return 0;
    }

    private function qdrant(array $args): int
    {
        $options = $this->parseQdrantOptions($args);
        $settings = is_array($this->config['qdrant'] ?? null) ? $this->config['qdrant'] : [];

        // QDRANT_TIMEOUT/--timeout menerima detik (< 1000) atau milidetik
        // (>= 1000, mis. 10000 = 10 detik); QdrantClient memakai satuan detik.
        $timeout = QdrantClient::timeoutSeconds($options['timeout'] ?? $settings['timeout'] ?? 120);

        $client = new QdrantClient(
            $this->httpClient(),
            (string) ($options['url'] ?? $settings['base_url'] ?? 'http://localhost:6333'),
            (string) ($options['api_key'] ?? $settings['api_key'] ?? ''),
            $timeout,
        );

        $logger = $this->logger('QDRANT-' . date('Ymd-His'));
        $push = new QdrantPush($settings, $this->config['paths'] ?? [], $client, $options, $logger);

        $this->title('QDRANT ' . $client->baseUrl());

        $stats = $push->run();

        $rows = [
            'Berkas vektor' => $stats['berkas'],
            'Mode' => $stats['mode'],
            'Qdrant' => $stats['url_qdrant'],
            'Collection' => $stats['collection'],
            'Collection dibuat' => $stats['collection_dibuat'] ? 'ya' : 'tidak',
            'Recreate' => !empty($stats['recreate']) ? 'ya' : 'tidak',
            'Dimensi harap' => ((int) ($stats['dimensi_harap'] ?? 0)) > 0 ? $stats['dimensi_harap'] : '-',
            'Dimensi' => $stats['dimensi'],
            'Point terbaca' => $stats['point_terbaca'],
            'Point terkirim' => $stats['point_terkirim'],
            'Point gagal' => $stats['point_gagal'],
            'Batch' => $stats['batch'] . ' (gagal ' . $stats['batch_gagal'] . ')',
            'Point di collection' => $stats['collection_point'] < 0 ? '-' : $stats['collection_point'],
        ];

        if ($stats['out_file'] !== null) {
            $rows['Berkas ekspor'] = $stats['out_file'];
        }

        if ($stats['out_json'] !== null) {
            $rows['Berkas JSON'] = $stats['out_json'];
            $rows['Point di JSON'] = $stats['point_json'];
            $rows['Ukuran JSON'] = Text::humanBytes((int) $stats['ukuran_json']);
        }

        $rows['Status'] = $stats['status'];
        $rows['Durasi'] = Text::duration((float) $stats['durasi']);

        $this->title('RINGKASAN');
        foreach ($rows as $label => $value) {
            $this->line('  ' . str_pad((string) $label, 22, '.') . ' : ' . $this->scalar($value));
        }

        foreach (array_slice((array) $stats['problem'], 0, 5) as $problem) {
            $this->warn($problem);
        }

        $collectionUrl = $stats['url_qdrant'] . '/collections/' . $stats['collection'];

        if ($stats['out_json'] !== null && $stats['status'] === 'SUKSES') {
            $this->line('');
            $this->line('  Unggah satu berkas (sekali kirim, PowerShell):');
            $this->line('  Invoke-RestMethod -Method Put -ContentType "application/json" `');
            $this->line('    -Uri "' . $collectionUrl . '/points?wait=true" `');
            $this->line('    -InFile ' . $stats['out_json']);
            $this->line('  (curl: curl -X PUT -H "Content-Type: application/json" --data-binary @' . $stats['out_json']);
            $this->line('         "' . $collectionUrl . '/points?wait=true")');
        } elseif ($stats['out_json'] !== null) {
            $this->line('');
            $this->line('  Ekspor JSON gagal: berkas .qdrant.json tidak dibuat/diubah.');
            $this->line('  Untuk run besar pakai unggahan bertahap: php bin/crawl.php qdrant --batch=64');
            $this->line('  atau ekspor NDJSON per batch        : php bin/crawl.php qdrant --out=storage/vektor-qdrant.ndjson');
        } elseif ($stats['out_file'] !== null) {
            $this->line('');
            $this->line('  Unggah manual (PowerShell) - satu baris berkas = satu batch:');
            $this->line('  Get-Content ' . $stats['out_file'] . ' | ForEach-Object {');
            $this->line('    Invoke-RestMethod -Method Put -ContentType application/json `');
            $this->line('      -Uri "' . $collectionUrl . '/points?wait=true" -Body $_ }');
        } elseif ($stats['mode'] === 'uji') {
            $this->line('');
            $this->line('  Mode uji (--dry-run): tidak ada data yang dikirim ke Qdrant.');
            $this->line('  Kirim sungguhan: php bin/crawl.php qdrant --create');
            $this->line('  (bila .env memuat QDRANT_AUTO_CREATE=true / QDRANT_RECREATE, --create tidak perlu)');
        } elseif ($stats['point_terkirim'] > 0) {
            $this->line('');
            $this->line('  Cek di dashboard Qdrant: ' . $stats['url_qdrant'] . '/dashboard');
            $this->line('  Cek jumlah point      : GET ' . $collectionUrl);
        }

        $this->line('');
        $this->line('  log run    : ' . $logger->runFile());

        return in_array($stats['status'], ['SUKSES', 'SUKSES_SEBAGIAN'], true) ? 0 : 1;
    }

    /**
     * Opsi perintah `qdrant`. Argumen tanpa awalan `--` diperlakukan sebagai
     * berkas (.jsonl) atau id site.
     *
     * @param list<string> $args
     *
     * @return array<string, mixed>
     */
    private function parseQdrantOptions(array $args): array
    {
        $options = [];

        foreach ($args as $arg) {
            if (!str_starts_with($arg, '--')) {
                if (str_ends_with(strtolower($arg), '.jsonl')) {
                    $options['file'] = $arg;
                } else {
                    $options['site'] = $arg;
                }

                continue;
            }

            $option = substr($arg, 2);
            $value = null;

            if (str_contains($option, '=')) {
                [$option, $value] = explode('=', $option, 2);
            }

            $key = strtolower(str_replace('-', '_', $option));

            switch ($key) {
                case 'file':
                case 'run':
                case 'site':
                case 'collection':
                case 'url':
                case 'api_key':
                case 'batch':
                case 'distance':
                case 'out':
                case 'export_json':
                case 'timeout':
                    $options[$key] = (string) ($value ?? '');
                    break;

                case 'max_points':
                case 'vector_size':
                    $options[$key] = (int) ($value ?? 0);
                    break;

                case 'create':
                case 'recreate':
                case 'dry_run':
                    $options[$key] = true;
                    break;

                default:
                    throw new \RuntimeException(
                        'Opsi qdrant tidak dikenal: --' . $option . PHP_EOL
                        . 'Opsi yang tersedia: --file= --site= --run= --collection= --url= --api-key= '
                        . '--batch=N --distance=Cosine --vector-size=N --create --recreate --out= '
                        . '--export-json[=path] --max-points=N --dry-run --timeout=N'
                    );
            }
        }

        return $options;
    }

    /**
     * Jalankan ULANG job (site + opsi yang sama) sebagai job BARU.
     *
     * Dipakai setelah run gagal di tengah jalan (mis. galat tak tertangani):
     * riwayat job lama tetap utuh, dan job baru ditautkan lewat kolom
     * ulangi_dari/ulangi_ke pada berkas job.
     *
     * @param list<string> $args
     */
    private function retry(array $args): int
    {
        $jobId = null;
        $autoRetry = null;

        foreach ($args as $arg) {
            if (preg_match('/^--job=(.+)$/', $arg, $match) === 1) {
                $jobId = trim($match[1]);
            } elseif (preg_match('/^--auto-retry=(\d+)$/', $arg, $match) === 1) {
                $autoRetry = max(0, (int) $match[1]);
            }
        }

        if ($jobId === null || $jobId === '') {
            $this->error('Sebutkan job yang diulang: php bin/crawl.php retry --job=<job-id>');

            return 1;
        }

        $jobs = new JobStore($this->config['paths'] ?? []);
        $hasil = (new CrawlJobService($jobs, base_path()))->retry($jobId, $autoRetry);

        $this->title('ULANGI JOB ' . $jobId);

        if (!$hasil['ok']) {
            $this->error($hasil['pesan']);

            return 1;
        }

        $this->line('  job baru   : ' . $hasil['job_id']);
        $this->line('  pid        : ' . $hasil['pid']);
        $this->line('  perintah   : ' . $hasil['command']);
        $this->line('  pantau     : php bin/crawl.php jobs --limit=5');
        $this->line('');

        return 0;
    }

    /**
     * Bersihkan sisa berkas/folder sementara render browser (folder profil
     * Chrome, DOM, NetLog) yang ditinggalkan proses yang berhenti mendadak.
     *
     * Batas umur bawaan 1 jam; `--umur=0` menghapus semua sisa (termasuk milik
     * render yang baru berjalan, jadi pakai hanya saat tidak ada crawl aktif).
     *
     * @param list<string> $args
     */
    private function cleanTemp(array $args): int
    {
        $jam = 1;
        foreach ($args as $arg) {
            if (preg_match('/^--umur=(\d+)$/', $arg, $match) === 1) {
                $jam = max(0, (int) $match[1]);
            }
        }

        $this->title('BERSIHKAN TEMP RENDER');
        $this->line('  folder temp : ' . sys_get_temp_dir());
        $this->line('  batas umur  : ' . ($jam === 0 ? 'semua sisa' : $jam . ' jam'));
        $this->line('');

        $dihapus = HeadlessRenderer::sweepTemp($jam * 3600);

        if ($dihapus === []) {
            $this->line('  tidak ada sisa berkas sementara yang perlu dihapus.');
            $this->line('');

            return 0;
        }

        $this->line('  ' . count($dihapus) . ' sisa berhasil dihapus:');
        foreach (array_slice($dihapus, 0, 20) as $nama) {
            $this->line('    - ' . $nama);
        }

        if (count($dihapus) > 20) {
            $this->line('    ... dan ' . (count($dihapus) - 20) . ' lainnya');
        }

        $this->line('');

        return 0;
    }

    /**
     * Tampilkan job crawl yang tercatat di storage/jobs (dibuat dari UI web).
     *
     * @param list<string> $args
     */
    private function jobs(array $args): int
    {
        $limit = 10;
        foreach ($args as $arg) {
            if (preg_match('/^--limit=(\d+)$/', $arg, $match) === 1) {
                $limit = max(1, (int) $match[1]);
            }
        }

        $store = new JobStore($this->config['paths'] ?? []);
        $jobs = $store->all($limit);

        $this->title('JOB CRAWL (' . count($jobs) . ')');
        $this->line('  folder: ' . $store->dir());
        $this->line('');

        if ($jobs === []) {
            $this->line('  belum ada job. Buat dari UI (public/index.php) atau jalankan crawl langsung.');
            $this->line('');

            return 0;
        }

        foreach ($jobs as $job) {
            $ringkasan = is_array($job['ringkasan'] ?? null) ? $job['ringkasan'] : [];
            $this->line(sprintf(
                '  %-32s %-9s mulai=%-19s dokumen=%-4d chunk=%-5d vektor=%-5d',
                (string) ($job['job_id'] ?? '-'),
                (string) ($job['status'] ?? '-'),
                (string) ($job['mulai'] ?? '-'),
                (int) ($ringkasan['documents'] ?? 0),
                (int) ($ringkasan['chunks'] ?? 0),
                (int) ($ringkasan['embedded'] ?? 0),
            ));

            $catatan = [];
            if ((int) ($job['percobaan_ke'] ?? 1) > 1) {
                $catatan[] = 'percobaan ke-' . (int) $job['percobaan_ke'];
            }
            if ((string) ($job['ulangi_dari'] ?? '') !== '') {
                $catatan[] = 'ulangan dari ' . (string) $job['ulangi_dari'];
            }
            if ((string) ($job['ulangi_ke'] ?? '') !== '') {
                $catatan[] = 'diulang oleh ' . (string) $job['ulangi_ke'];
            }
            if ((int) ($job['auto_retry'] ?? 0) > 0) {
                $catatan[] = 'auto-retry sisa ' . (int) $job['auto_retry'];
            }
            if ((string) ($job['dijeda_sejak'] ?? '') !== '') {
                $catatan[] = 'dijeda sejak ' . (string) $job['dijeda_sejak'];
            } elseif ($store->pauseRequested((string) ($job['job_id'] ?? ''))
                && (string) ($job['jeda_diminta'] ?? '') !== '') {
                $catatan[] = 'menunggu jeda (permintaan ' . (string) $job['jeda_diminta'] . ')';
            }
            if ((float) ($job['total_jeda_detik'] ?? 0) > 0) {
                $catatan[] = 'total jeda ' . Text::duration((float) $job['total_jeda_detik']);
            }

            $this->line('    site  : ' . implode(', ', array_map('strval', (array) ($job['sites'] ?? []))));
            if ($catatan !== []) {
                $this->line('    catat : ' . implode('; ', $catatan));
            }
            $this->line('    pesan : ' . (string) ($job['pesan'] ?? '-'));
            $this->line('    log   : ' . (string) ($job['log_run'] ?? '-'));
        }
        $this->line('');

        return 0;
    }

    /**
     * Pisahkan argumen posisi (id site) dari opsi --nama=nilai.
     *
     * @param list<string> $args
     *
     * @return array{sites: list<string>, run: array<string, mixed>, job: string|null}
     */
    private function parseOptions(array $args): array
    {
        $sites = [];
        $run = [];
        $job = null;

        foreach ($args as $arg) {
            if (!str_starts_with($arg, '--')) {
                $sites[] = $arg;

                continue;
            }

            $option = substr($arg, 2);
            $value = null;
            if (str_contains($option, '=')) {
                [$option, $value] = explode('=', $option, 2);
            }

            switch (strtolower(str_replace('-', '_', $option))) {
                case 'job':
                    $job = $value === null || $value === '' ? null : $value;
                    break;

                case 'site':
                case 'sites':
                    foreach (explode(',', (string) $value) as $id) {
                        $id = trim($id);
                        if ($id !== '') {
                            $sites[] = $id;
                        }
                    }
                    break;

                case 'max_pages':
                case 'limit':
                    // 0 = tanpa batas (bawaan).
                    $run['max_pages'] = max(0, (int) $value);
                    break;

                case 'max_depth':
                    // -1 = TANPA BATAS (seluruh situs dijelajahi).
                    $run['max_depth'] = max(-1, (int) $value);
                    break;

                case 'max_requests':
                case 'max_permintaan':
                    $run['max_requests'] = max(0, (int) $value);
                    break;

                case 'concurrency':
                case 'konkurensi':
                    $run['concurrency'] = max(1, (int) $value);
                    break;

                case 'no_follow':
                    $run['follow_links'] = false;
                    break;

                case 'follow':
                    $run['follow_links'] = true;
                    break;

                case 'no_save_html':
                    $run['save_html'] = false;
                    break;

                case 'save_html':
                    $run['save_html'] = true;
                    break;

                case 'no_robots':
                    $run['respect_robots'] = false;
                    break;

                case 'robots':
                    $run['respect_robots'] = true;
                    break;

                case 'render':
                case 'render_js':
                    $run['render'] = (string) $value;
                    break;

                case 'render_js_only':
                case 'always_render':
                    $run['render'] = 'always';
                    break;

                case 'no_render':
                case 'no_render_js':
                    $run['render'] = 'off';
                    break;

                case 'push_qdrant':
                case 'qdrant':
                    $run['push_qdrant'] = true;
                    break;

                case 'no_push_qdrant':
                case 'no_qdrant':
                    $run['push_qdrant'] = false;
                    break;

                case 'follow_documents':
                case 'follow_document_links':
                case 'ikuti_dokumen':
                    // Tautan .pdf/.docx/.xlsx pada halaman web ikut diunduh dan
                    // diproses sebagai dokumen (lihat Pipeline::isDocumentUrl()).
                    $run['follow_document_links'] = true;
                    break;

                case 'no_follow_documents':
                case 'no_follow_document_links':
                case 'tanpa_dokumen':
                    $run['follow_document_links'] = false;
                    break;

                case 'follow_external':
                case 'jelajah_situs_lain':
                    // off | family | all (lihat SiteConfig::followExternalMode).
                    $run['follow_external'] = SiteConfig::followExternalMode($value);
                    break;

                case 're_crawl':
                case 'recrawl':
                case 'paksa':
                    // Lewati pengecualian anti-duplikat MySQL.
                    $run['re_crawl'] = true;
                    break;

                case 'auto_retry':
                case 'auto_ulangi':
                case 'ulangi_otomatis':
                    // Jatah pengulangan otomatis setelah galat fatal (0 = mati).
                    $run['auto_retry'] = max(0, (int) $value);
                    break;

                default:
                    throw new \RuntimeException(
                        'Opsi tidak dikenal: --' . $option . PHP_EOL
                        . 'Opsi yang tersedia: --job=ID --site=id1,id2 --max-pages=N --max-depth=N '
                        . '--max-requests=N --concurrency=N '
                        . '--follow --no-follow --save-html --no-save-html --robots --no-robots '
                        . '--render=auto|always|off --no-render --push-qdrant --no-push-qdrant '
                        . '--follow-documents --no-follow-documents --auto-retry=N'
                    );
            }
        }

        return ['sites' => array_values(array_unique($sites)), 'run' => $run, 'job' => $job];
    }

    /**
     * Tampilkan lokasi log txt dan cuplikan log run terakhir.
     *
     * @param list<string> $args
     */
    private function logs(array $args): int
    {
        $tail = 30;
        foreach ($args as $arg) {
            if (preg_match('/^--tail=(\d+)$/', $arg, $match) === 1) {
                $tail = max(1, (int) $match[1]);
            }
        }

        $logsDir = Config::path('logs', false);
        $runsDir = Config::path('runs', false);
        $sitesDir = Config::path('sites', false);

        $this->title('LOG TXT');
        $this->line('  harian : ' . $logsDir . DIRECTORY_SEPARATOR . 'crawl-' . date('Y-m-d') . '.txt');
        $this->line('  per run: ' . $runsDir);
        $this->line('  per web: ' . $sitesDir);

        $pointer = $logsDir . DIRECTORY_SEPARATOR . 'terbaru.txt';
        $runFile = null;

        if (is_file($pointer)) {
            foreach (preg_split('/\r\n|\r|\n/', (string) file_get_contents($pointer)) ?: [] as $line) {
                if (str_starts_with($line, 'log_run')) {
                    $runFile = trim(substr($line, strpos($line, ':') + 1));
                }
            }
        }

        if ($runFile === null || !is_file($runFile)) {
            $this->warn('Belum ada log run. Jalankan: php bin/crawl.php crawl');

            return 0;
        }

        $this->line('');
        $this->title('CUPLIKAN ' . basename($runFile) . ' (terakhir ' . $tail . ' baris)');

        $lines = preg_split('/\r\n|\r|\n/', (string) file_get_contents($runFile)) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));

        foreach (array_slice($lines, -$tail) as $line) {
            $this->line($line);
        }
        $this->line('');

        return 0;
    }

    private function help(): int
    {
        $this->title('CRAWLER WEB -> PARSE -> EMBED');
        $this->line('Pemakaian: php bin/crawl.php <perintah> [opsi]');
        $this->line('');
        $this->line('Perintah:');
        $this->line('  health                cek koneksi service worker-ingest (/health)');
        $this->line('  list                  tampilkan web pada config/sites.json');
        $this->line('  crawl [site ...]      unduh HTML -> /parse -> chunk -> /embed -> simpan vektor');
        $this->line('                        (markdown dibersihkan dari menu/footer, lalu otomatis push ke Qdrant)');
        $this->line('  documents [site ...]  proses ULANG berkas dokumen (PDF/DOCX/XLSX) yang sudah ada di');
        $this->line('                        storage/documents: /parse -> /embed -> Qdrant, TANPA crawl ulang');
        $this->line('  estimate [site ...]   perkirakan lama crawl SEBELUM dijalankan (kalibrasi dari riwayat run)');
        $this->line('  logs [--tail=N]       lokasi log txt + cuplikan log run terakhir');
        $this->line('  jobs [--limit=N]      daftar job crawl (status, jeda, pengulangan)');
        $this->line('  retry --job=ID        jalankan ULANG job dengan site + opsi yang sama');
        $this->line('  temp [--umur=jam]     bersihkan sisa folder profil browser di folder temp');
        $this->line('  qdrant [opsi]         unggah berkas vektor ke Qdrant (atau ekspor JSON/NDJSON)');
        $this->line('  help                  bantuan ini');
        $this->line('');
        $this->line('Jeda / lanjutkan job (tanpa membatalkan proses):');
        $this->line('  Job dibuat dari dashboard (UI web) bisa DIJEDA lalu DILANJUTKAN: UI menulis');
        $this->line('  berkas storage/jobs/<job-id>.pause, proses CLI memeriksanya di titik aman');
        $this->line('  (antar batch halaman / antar site) lalu menunggu sampai berkas itu dihapus.');
        $this->line('  Tidak ada unduhan yang terpotong: batch yang sedang berjalan diselesaikan dulu.');
        $this->line('');
        $this->line('Opsi crawl:');
        $this->line('  --job=ID                      tandai job pada storage/jobs (dipakai UI web)');
        $this->line('  --site=id1,id2                batasi ke site tertentu (nama site bisa juga)');
        $this->line('  --max-pages=N                 batas halaman per site untuk run ini (0 = TANPA BATAS,');
        $this->line('                                bawaan; seluruh halaman/berkas yang ditemukan diproses)');
        $this->line('  --max-depth=N                 batas kedalaman tautan (0 = hanya start_url,');
        $this->line('                                -1 = TANPA BATAS: seluruh situs dijelajahi, BAWAAN)');
        $this->line('  --max-requests=N              batas TOTAL permintaan satu run lintas site (0 = bebas)');
        $this->line('  --concurrency=N               jumlah halaman diunduh bersamaan (1 = satu per satu)');
        $this->line('  --follow / --no-follow        aktif/nonaktif crawling berantai');
        $this->line('  --save-html / --no-save-html  simpan HTML mentah ke storage/html');
        $this->line('  --robots / --no-robots        hormati robots.txt atau abaikan');
        $this->line('  --render=auto|always|off      render halaman dengan browser headless (Chrome/Edge):');
        $this->line('                                auto = hanya halaman kerangka JavaScript (bawaan),');
        $this->line('                                always = semua halaman, off = HTML unduhan apa adanya');
        $this->line('  --no-render                   sama dengan --render=off untuk run ini');
        $this->line('  --follow-documents            ikuti tautan berkas dokumen (.pdf/.docx/.xlsx) pada');
        $this->line('                                halaman web: diunduh apa adanya lalu dikirim ke /parse');
        $this->line('  --no-follow-documents         lewati tautan dokumen (hanya entri dokumen pada');
        $this->line('                                config/sites.json yang diambil)');
        $this->line('  --follow-external=MODE        jelajahi SITUS LAIN yang ditautkan halaman:');
        $this->line('                                off = hanya host pada URL target,');
        $this->line('                                family = satu keluarga domain (mis. *.kemendagri.go.id),');
        $this->line('                                all = semua host yang ditautkan (dibatasi jumlah host)');
        $this->line('  --re-crawl                    paksa crawl walau website sudah pernah sukses');
        $this->line('                                (menimpa pengecualian anti-duplikat MySQL)');
        $this->line('  --push-qdrant                 unggah hasil /embed ke Qdrant begitu site selesai');
        $this->line('  --no-push-qdrant              lewati push Qdrant untuk run ini (crawl saja)');
        $this->line('  --auto-retry=N                ulangi OTOMATIS job ini sampai N kali bila proses');
        $this->line('                                berhenti karena galat fatal (bawaan: CRAWLER_AUTO_RETRY)');
        $this->line('');
        $this->line('Lingkungan batas crawl (.env) - bawaan TANPA BATAS agar hasil maksimal:');
        $this->line('  CRAWL_MAX_PAGES=0            batas halaman per site (0 = tanpa batas, BAWAAN)');
        $this->line('  MAX_REQUESTS_PER_CRAWL=0     batas TOTAL unduhan satu run lintas site');
        $this->line('                               (0 = tanpa batas, BAWAAN)');
        $this->line('  CRAWL_MAX_DEPTH=-1           kedalaman tautan (-1 = TANPA BATAS, BAWAAN)');
        $this->line('  CRAWL_CONCURRENCY=5          jumlah halaman diunduh bersamaan per batch');
        $this->line('  Opsi --max-pages=N / --max-requests=N dan kolom pada dashboard menimpa');
        $this->line('  nilai di atas untuk satu run. Bila batas tercapai, log mencatat');
        $this->line('  LIMIT sebab=max_pages atau max_requests_per_crawl.');
        $this->line('');
        $this->line('Lingkungan render halaman JS (CRAWLER_* pada .env):');
        $this->line('  CRAWLER_JS_RENDER=auto|always|off   mode render bawaan semua site');
        $this->line('  CRAWLER_BROWSER=path/chrome.exe     biner browser (kosong = deteksi otomatis;');
        $this->line('                                      "none" = matikan render)');
        $this->line('  CRAWLER_BROWSER_WAIT=6000           waktu (ms) untuk JavaScript + pengambilan data');
        $this->line('  CRAWLER_BROWSER_TIMEOUT=25000       batas proses browser per halaman (ms)');
        $this->line('  CRAWLER_BROWSER_FLAGS               argumen tambahan, mis. --ignore-certificate-errors');
        $this->line('  CRAWLER_BROWSER_UA                  UA browser headless (kosong = UA Chrome)');
        $this->line('  CRAWLER_RENDER_TEMP_HOURS=6         umur maksimal (jam) sisa folder profil browser/');
        $this->line('                                      DOM/NetLog yang dibersihkan otomatis di folder temp');
        $this->line('                                      (proses yang berhenti mendadak tidak sempat membersihkan)');
        $this->line('  CRAWLER_PAUSE_POLL_MS=1000          jeda pemeriksaan perintah JEDA dari dashboard (ms)');
        $this->line('  CRAWLER_AUTO_RETRY=1                pengulangan OTOMATIS bila run gagal fatal (0 = mati)');
        $this->line('');
        $this->line('Lingkungan koneksi (.env):');
        $this->line('  CRAWLER_REQUEST_TIMEOUT=60   batas waktu SATU unduhan (detik)');
        $this->line('  CRAWLER_CONNECT_TIMEOUT=10   batas waktu TAHAP KONEKSI (detik). Host dengan');
        $this->line('                               beberapa alamat A -- sebagian dibuang firewall --');
        $this->line('                               membuat curl menunggu sampai batas ini habis');
        $this->line('                               (mis. dukcapil.kemendagri.go.id: 118.97.79.23 mati,');
        $this->line('                               36.66.118.179 normal). Pakai nilai kecil (5-10).');
        $this->line('  CRAWLER_RETRY_ADDRESSES=true kegagalan tahap koneksi dicoba ULANG ke alamat IP');
        $this->line('                               lain hasil resolusi host yang sama (tahap ADDR_RETRY');
        $this->line('                               pada log); alamat yang berhasil dipakai untuk sisa');
        $this->line('                               run. false = perilaku lama (satu percobaan saja).');
        $this->line('');
        $this->line('Lingkungan tautan dokumen (.env):');
        $this->line('  CRAWLER_FOLLOW_DOCUMENT_LINKS=true  tautan .pdf/.docx/.xlsx yang ditemukan pada halaman');
        $this->line('                                      web ikut diunduh & diproses sebagai dokumen (default:');
        $this->line('                                      false = tautan dokumen dilewati)');
        $this->line('  CRAWLER_DOCUMENT_MAX_BYTES=67108864 batas ukuran unduhan satu berkas dokumen (64 MB)');
        $this->line('  CRAWLER_DELETE_DOCUMENTS_AFTER_PUSH=true');
        $this->line('                               berkas dokumen mentah (pdf/docx/xlsx) DIHAPUS setelah');
        $this->line('                               vektornya terkirim ke Qdrant (markdown + isi vektor tetap');
        $this->line('                               ada); dokumen gagal parse/embed tidak dihapus. Per entri');
        $this->line('                               sites.json: "delete_documents_after_push": false.');
        $this->line('  CRAWLER_AUTO_DOCUMENTS=true  temukan berkas dokumen OTOMATIS dari log jaringan');
        $this->line('                               browser (NetLog) pada halaman yang dirender: tautan');
        $this->line('                               unduhan yang TIDAK ADA di HTML (dibuat JavaScript,');
        $this->line('                               mis. daftar PDF /informasi-publik Kemendagri) tetap');
        $this->line('                               ditemukan, diunduh, diparse, di-embed, dan dikirim ke');
        $this->line('                               Qdrant TANPA aturan per situs. Log: DOC_AUTO,');
        $this->line('                               DOC_AUTO_QUEUE. Per entri sites.json:');
        $this->line('                               "auto_documents": false.');
        $this->line('  CRAWLER_AUTO_DOCUMENTS_MAX=200   batas jumlah berkas per halaman');
        $this->line('  CRAWLER_AUTO_DOCUMENTS_APIS=8    batas endpoint API dipanggil ulang per halaman');
        $this->line('');
        $this->line('Lingkungan chunk (.env):');
        $this->line('  CHUNK_SIZE / CHUNK_OVERLAP / CHUNK_MIN_LENGTH   ukuran, tumpang tindih, panjang minimum chunk');
        $this->line('  CHUNK_CLEAN_MARKDOWN=true    buang menu/ornamen/footer dari markdown /parse');
        $this->line('                               (per entri sites.json: "clean_markdown": false)');
        $this->line('  CHUNK_MAX_LINK_RATIO=0.6     batas rasio markup tautan per chunk (1 = tanpa batas)');
        $this->line('  CHUNK_DROP_DUPLICATE_CONTENT=true  isi halaman yang identik/mirip dengan URL lain pada');
        $this->line('                               site yang sama hanya dikirim sekali ke /embed');
        $this->line('                               (per entri sites.json: "drop_duplicate_content": false)');
        $this->line('  CHUNK_DUPLICATE_SIMILARITY=0.85    batas kemiripan isi yang dianggap sama (1.0 = hanya identik)');
        $this->line('');
        $this->line('Entri config/sites.json (bentuk ringkas):');
        $this->line('  url (wajib), documentType, province, city, year   isi "" bila belum ada (diabaikan)');
        $this->line('  opsional: id, name, enabled, kind, follow_links, max_pages, max_depth,');
        $this->line('            chunk_size, chunk_overlap, clean_markdown, max_link_ratio,');
        $this->line('            drop_duplicate_content, duplicate_similarity, js_render, content_api,');
        $this->line('            document_api, include_patterns, exclude_patterns, metadata');
        $this->line('  js_render: situs berbasis JavaScript (isi halaman dibuat di browser) dirender');
        $this->line('            dengan browser headless sebelum di-parse - bawaan "auto" (hanya halaman');
        $this->line('            kerangka yang dirender), tidak perlu aturan API sama sekali.');
        $this->line('            Bentuk: false | "off" | "auto" | "always" |');
        $this->line('            {"mode": "auto", "wait_ms": 6000, "timeout_ms": 25000, "bin": "",');
        $this->line('             "flags": "", "user_agent": "", "min_text": 2000, "ratio": 0.03}');
        $this->line('            Per situs juga bisa: "js_render": false untuk halaman statis.');
        $this->line('  content_api: cadangan bila render tidak mungkin (mis. tanpa browser headless):');
        $this->line('            isi halaman diambil dari API resmi situs, mis.');
        $this->line('            "content_api": {"rules": [{"match": "/profil/", "url": ".../slug/{slug}",');
        $this->line('            "field": "data.pages_desc", "title_field": "data.pages_name"}]}');
        $this->line('            (placeholder {slug} {path} {host} {query} {url} {1}..{n}; aturan tanpa');
        $this->line('            "match" berlaku untuk semua URL; kegagalan API -> kembali ke /parse).');
        $this->line('  document_api: daftar BERKAS DOKUMEN dari API JSON situs, untuk tautan unduhan');
        $this->line('            yang TIDAK PERNAH ada di HTML/DOM (baru dibuat JavaScript), mis.');
        $this->line('            "document_api": {"url": "https://backend.situs.go.id/api/v1/informasi-publik/public",');
        $this->line('            "items_field": "data.informasi", "children_field": "children",');
        $this->line('            "link_field": "file", "title_field": "item",');
        $this->line('            "base_url": "https://backend.situs.go.id/uploads", "extensions": ["pdf"]}');
        $this->line('            (url wajib; items_field kosong = seluruh badan respons; base_url = awalan');
        $this->line('            untuk path relatif; extensions kosong = semua ekstensi dokumen biasa).');
        $this->line('            URL hasilnya diproses sebagai DOKUMEN walau host-nya berbeda dari halaman');
        $this->line('            situs (robots.txt host berkas tetap dihormati) dan judul item dari API');
        $this->line('            dipakai sebagai judul dokumen. Dicatat pada log: DOC_API, DOC_API_QUEUE,');
        $this->line('            DOC_API_SKIP. Beberapa aturan sekaligus: {"rules": [ {...}, {...} ]}.');
        $this->line('  id dibuat otomatis dari host URL (mis. www-kemendagri-go-id); field "name" hanya');
        $this->line('  label tampilan pada log/list/site_name - kosong berarti memakai host URL.');
        $this->line('');
        $this->line('Opsi documents (proses ulang berkas dokumen yang belum masuk Qdrant):');
        $this->line('  --site=id1,id2        batasi ke site tertentu (nama site bisa juga)');
        $this->line('  --limit=N             batasi jumlah berkas per site (0 = semua)');
        $this->line('  --url=URL             paksa URL asli sebuah berkas bila URL-nya tidak terbaca dari');
        $this->line('                        markdown/HTML site (boleh diulang; berkas dicocokkan lewat');
        $this->line('                        document_id = UUID v5 URL pada nama berkas)');
        $this->line('  --push-qdrant         kirim hasil /embed ke Qdrant (bawaan: QDRANT_PUSH_AFTER_CRAWL)');
        $this->line('  --no-push-qdrant      hanya membuat berkas vektor/.qdrant.json (tanpa kirim)');
        $this->line('  --dry-run             tampilkan berkas & URL yang akan diproses, tanpa parse/embed');
        $this->line('  Berkas mentah dihapus setelah vektornya terkirim (kecuali delete_documents_after_push=false).');
        $this->line('  Kejadian di log: DOK_RESUME, DOK_RESUME_END, DOC_TERTUNDA, DOC_CLEANUP.');
        $this->line('');
        $this->line('Opsi estimate (perkiraan lama crawl SEBELUM run):');
        $this->line('  --halaman=N                   jumlah halaman per web (0 = pakai rata-rata riwayat)');
        $this->line('  --render=auto|always|off      asumsi mode render browser pada perkiraan');
        $this->line('  Angka dikalibrasi dari logs/runs/*.txt + logs/crawl-*.txt: waktu per unduhan,');
        $this->line('  per permintaan /parse, per vektor /embed, dan vektor per halaman.');
        $this->line('  Halaman web: menu "Estimasi" (estimate.php).');
        $this->line('');
        $this->line('Opsi qdrant:');
        $this->line('  --file=storage/vectors/<site>/<run>.jsonl  berkas vektor sumber (default: .jsonl terbaru)');
        $this->line('  --site=id / --run=RUN-...                  pilih berkas dari site / run tertentu');
        $this->line('  --url=--api-key=--collection=              tujuan Qdrant (default dari QDRANT_* pada .env)');
        $this->line('  --create / --recreate                      buat / buat ulang collection (size + distance)');
        $this->line('  --batch=N --distance=Cosine                jumlah point per permintaan & metrik jarak');
        $this->line('  --vector-size=N                            dimensi vektor (bawaan QDRANT_VECTOR_SIZE=1024)');
        $this->line('  --timeout=N                                timeout Qdrant: detik (<1000) atau ms (>=1000)');
        $this->line('  --out=storage/vektor-qdrant.ndjson         ekspor batch payload untuk diunggah manual');
        $this->line('  --export-json[=path]                       buat SATU berkas JSON {"points":[...]} siap');
        $this->line('                                             kirim (default <run>.qdrant.json), tanpa Qdrant');
        $this->line('  --max-points=N                             batas point untuk --export-json (0 = tanpa batas)');
        $this->line('  --dry-run                                  validasi berkas tanpa menulis/mengirim data');
        $this->line('');
        $this->line('Lingkungan qdrant (.env):');
        $this->line('  QDRANT_URL / QDRANT_API_KEY / QDRANT_COLLECTION   tujuan & collection Qdrant');
        $this->line('  QDRANT_VECTOR_SIZE / EMBED_DIMENSION              dimensi vektor (BGE-M3 = 1024)');
        $this->line('  QDRANT_DISTANCE                                   Cosine | Euclid | Dot | Manhattan');
        $this->line('  QDRANT_RECREATE=true                              hapus lalu buat ulang collection');
        $this->line('  QDRANT_RECREATE=false / QDRANT_AUTO_CREATE=true   buat collection bila belum ada');
        $this->line('  QDRANT_TIMEOUT                                    detik (<1000) atau ms (>=1000)');
        $this->line('  QDRANT_BATCH_SIZE / QDRANT_RETRIES                ukuran batch & percobaan ulang');
        $this->line('  QDRANT_PUSH_AFTER_CRAWL=true                      crawl langsung push ke Qdrant');
        $this->line('');
        $this->line('Contoh:');
        $this->line('  php bin/crawl.php health');
        $this->line('  php bin/crawl.php crawl --max-pages=3 --no-follow');
        $this->line('  php bin/crawl.php crawl --no-push-qdrant          # crawl saja, tanpa push Qdrant');
        $this->line('  php bin/crawl.php crawl kemendagri --max-depth=2');
        $this->line('  php bin/crawl.php documents --dry-run             # lihat PDF yang belum masuk Qdrant');
        $this->line('  php bin/crawl.php documents --site=ppid-kemendagri-go-id --push-qdrant');
        $this->line('  php bin/crawl.php qdrant --dry-run');
        $this->line('  php bin/crawl.php qdrant --create --collection=documents');
        $this->line('  php bin/crawl.php qdrant --export-json            # buat <run>.qdrant.json');
        $this->line('  php bin/crawl.php qdrant kemendagri --out=storage/vektor-qdrant.ndjson');
        $this->line('');

        return 0;
    }

    private function unknown(string $command): int
    {
        $this->error('Perintah tidak dikenal: ' . $command);
        $this->help();

        return 2;
    }

    private function service(): IngestServiceClient
    {
        return new IngestServiceClient($this->httpClient(), $this->config['service'] ?? []);
    }

    private function httpClient(): HttpClient
    {
        $crawl = $this->config['crawl'] ?? [];
        $service = $this->config['service'] ?? [];

        return new HttpClient(
            userAgent: (string) ($crawl['user_agent'] ?? 'crawler-embed/1.0'),
            connectTimeout: (int) ($service['connect_timeout'] ?? 10),
            maxRedirects: (int) ($crawl['max_redirects'] ?? 5),
            maxBytes: (int) ($crawl['max_bytes'] ?? 5_242_880),
            verifyTls: (bool) ($crawl['verify_tls'] ?? true),
            insecureRetry: (bool) ($crawl['insecure_retry'] ?? true),
            caBundle: (string) ($crawl['ca_bundle'] ?? ''),
        );
    }

    private function logger(string $runId): Logger
    {
        return new Logger(
            $this->config['logging'] ?? [],
            $this->config['paths'] ?? [],
            $runId,
            true,
        );
    }

    private function runId(): string
    {
        return 'RUN-' . date('Ymd-His');
    }

    private function summary(RunResult $result): void
    {
        $this->title('RINGKASAN');
        foreach ($result->summaryRows() as $label => $value) {
            if ($label === 'Total byte HTML' && is_numeric($value)) {
                $value = Text::humanBytes((int) $value);
            }

            $this->line('  ' . str_pad((string) $label, 22, '.') . ' : ' . $this->scalar($value));
        }

        foreach ($result->sites as $stat) {
            $this->line(sprintf(
                '  %-16s dokumen=%-3d chunk=%-3d vektor=%-4d gagal_unduh=%-3d durasi=%ss',
                (string) $stat['id'],
                (int) $stat['dokumen'],
                (int) $stat['chunk'],
                (int) $stat['vektor'],
                (int) $stat['gagal_unduh'],
                number_format((float) $stat['durasi'], 2, '.', '')
            ));
        }

        if ($result->qdrantRuns !== []) {
            $this->line('');
            $this->line('  Push otomatis ke Qdrant:');
            foreach ($result->qdrantRuns as $push) {
                $this->line(sprintf(
                    '  %-16s collection=%-24s terkirim=%-4d gagal=%-3d status=%s',
                    (string) $push['site'],
                    (string) $push['collection'],
                    (int) $push['point_terkirim'],
                    (int) $push['point_gagal'],
                    (string) $push['status']
                ));

                if ((string) $push['catatan'] !== '') {
                    $this->line('                   catatan: ' . (string) $push['catatan']);
                }
            }

            if ($result->qdrantIncomplete()) {
                $this->line('  Ulangi yang gagal  : php bin/crawl.php qdrant --file=storage/vectors/<site>/<run>.jsonl');
            }
        }

        $this->line('');
    }

    private function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '-';
        }

        return is_scalar($value) ? (string) $value : (string) json_encode($value);
    }

    private function title(string $text): void
    {
        echo PHP_EOL . '=== ' . $text . ' ' . str_repeat('=', max(0, 60 - strlen($text))) . PHP_EOL;
    }

    private function line(string $text): void
    {
        echo $text . PHP_EOL;
    }

    private function warn(string $text): void
    {
        echo '[PERINGATAN] ' . $text . PHP_EOL;
    }

    private function error(string $text): void
    {
        echo '[GAGAL] ' . Text::oneLine($text, 400) . PHP_EOL;
    }
}
