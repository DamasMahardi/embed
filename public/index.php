<?php

declare(strict_types=1);

/**
 * Dashboard crawler: status service, daftar web, pemulai crawl (job latar
 * belakang), dan status job terakhir.
 *
 * Halaman ini hanya alat bantu lokal (default: hanya bisa dibuka dari
 * 127.0.0.1/::1). Semua pekerjaan berat tetap dilakukan oleh CLI
 * `php bin/crawl.php crawl --job=<id>`.
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\Crawl\SiteConfig;
use App\Crawl\SiteCatalogWriter;
use App\Crawl\SiteRepository;
use App\Http\HttpClient;
use App\Service\IngestServiceClient;
use App\Service\CrawlJobService;
use App\Support\Config;
use App\Support\JobStore;
use App\Support\Text;
use App\Ui\DashboardView;
use App\Ui\Ui;

$config = Config::app();
Ui::guard($config['ui'] ?? []);

$jobs = new JobStore($config['paths'] ?? []);
$repository = new SiteRepository();

$notice = null;

/** Hitung baris berkas tanpa memuat seluruh isi ke memori. */
$countLines = static function (string $file): int {
    if (!is_file($file)) {
        return 0;
    }

    $handle = @fopen($file, 'rb');
    if ($handle === false) {
        return 0;
    }

    $lines = 0;
    while (!feof($handle)) {
        $buffer = fread($handle, 262144);
        if ($buffer === false || $buffer === '') {
            break;
        }
        $lines += substr_count($buffer, "\n");
    }
    fclose($handle);

    return $lines;
};

// ---------------------------------------------------------------------------
// Aksi POST: buat job + jalankan CLI di latar belakang.
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['aksi'] ?? '') === 'tambah_site') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $urls = preg_split('/[\r\n,]+/', (string) ($_POST['start_urls'] ?? '')) ?: [];
    $urls = array_values(array_filter(array_map(static fn (string $url): string => trim($url), $urls)));

    // ID dan nama boleh dikosongkan (bentuk ringkas config/sites.json tidak
    // mewajibkan "name"): ID diambil dari nama bila ada, lalu dari host URL
    // target — sama seperti id otomatis pada SiteRepository.
    $id = Text::slug(trim((string) ($_POST['id'] ?? '')), 60);
    if ($id === '') {
        $id = Text::slug($name, 60);
    }
    if ($id === '' && $urls !== []) {
        $id = Text::slug(SiteConfig::idForUrl($urls[0]), 60);
    }

    $invalidUrl = false;
    foreach ($urls as $url) {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string) ($parts['host'] ?? '')) === '') {
            $invalidUrl = true;
            break;
        }
    }

    if ($id === '' || $urls === [] || $invalidUrl) {
        header('Location: index.php?tipe=error&pesan=' . rawurlencode(
            'Minimal satu URL http/https wajib diisi dengan benar (ID dibuat otomatis dari host URL bila dikosongkan).'
        ) . '#tambah-site');
        exit;
    }

    $metadata = [];
    foreach (['document_type', 'province', 'city'] as $key) {
        $value = trim((string) ($_POST[$key] ?? ''));
        if ($value !== '') {
            $metadata[$key] = $value;
        }
    }
    $year = trim((string) ($_POST['year'] ?? ''));
    if ($year !== '' && ctype_digit($year)) {
        $metadata['year'] = (int) $year;
    }

    $exclude = preg_split('/[\r\n]+/', (string) ($_POST['exclude_patterns'] ?? '')) ?: [];
    $exclude = array_values(array_filter(array_map(static fn (string $value): string => trim($value), $exclude)));

    try {
        (new SiteCatalogWriter(base_path('config/sites.json')))->add(
            $id,
            $name,
            $urls,
            $metadata,
            $exclude,
            isset($_POST['enabled']),
        );
    } catch (Throwable $exception) {
        header('Location: index.php?tipe=error&pesan=' . rawurlencode($exception->getMessage()) . '#tambah-site');
        exit;
    }

    header('Location: index.php?tipe=info&pesan=' . rawurlencode('Web ' . $id . ' berhasil ditambahkan.') . '#sites');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['aksi'] ?? '') === 'mulai') {
    $siteIds = [];
    foreach ((array) ($_POST['site'] ?? []) as $id) {
        $id = trim((string) $id);
        if ($id !== '') {
            $siteIds[] = $id;
        }
    }

    $options = [
        'max_pages' => max(0, (int) ($_POST['max_pages'] ?? 0)),
        'max_depth' => max(0, (int) ($_POST['max_depth'] ?? 0)),
        'max_requests' => max(0, (int) ($_POST['max_requests'] ?? 0)),
        'concurrency' => max(1, (int) ($_POST['concurrency'] ?? 1)),
        'follow_links' => isset($_POST['follow_links']) || (int) ($_POST['max_depth'] ?? 0) > 0,
        'save_html' => isset($_POST['save_html']),
        'respect_robots' => isset($_POST['robots']),
        // "on"/"off" dari hidden + checkbox; kosong = pakai bawaan .env.
        'follow_documents' => (string) ($_POST['follow_documents'] ?? ''),
        // Pengulangan otomatis bila proses berhenti karena galat fatal.
        'auto_retry' => max(0, (int) ($_POST['auto_retry'] ?? 0)),
    ];

    $launch = (new CrawlJobService($jobs, base_path()))->start($siteIds, $options);
    $jobId = $launch['job_id'];

    $pesan = $launch['ok']
        ? 'Job ' . $jobId . ' dijalankan di latar belakang. Halaman ini akan memperbarui status otomatis.'
        : 'Job ' . $jobId . ' dibuat tetapi proses gagal dilepas: ' . $launch['pesan'];

    header('Location: index.php?job=' . rawurlencode($jobId)
        . '&tipe=' . ($launch['ok'] ? 'info' : 'error')
        . '&pesan=' . rawurlencode($pesan));
    exit;
}

// ---------------------------------------------------------------------------
// Aksi POST: kendalikan job yang sudah ada (jeda / lanjutkan / ulangi).
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && in_array((string) ($_POST['aksi'] ?? ''), ['jeda', 'lanjut', 'ulangi'], true)) {
    $aksi = (string) $_POST['aksi'];
    $jobId = trim((string) ($_POST['job_id'] ?? ''));

    $redirectJob = static function (string $jobId, string $tipe, string $pesan): never {
        header('Location: index.php?job=' . rawurlencode($jobId)
            . '&tipe=' . ($tipe === 'error' ? 'error' : 'info')
            . '&pesan=' . rawurlencode($pesan));
        exit;
    };

    if ($jobId === '' || preg_match('/^[A-Za-z0-9_-]+$/', $jobId) !== 1) {
        header('Location: index.php?tipe=error&pesan=' . rawurlencode('ID job tidak valid.'));
        exit;
    }

    if ($aksi === 'jeda') {
        $job = $jobs->requestPause($jobId);

        if ($job === null) {
            $redirectJob($jobId, 'error', 'Job tidak ditemukan: ' . $jobId);
        }

        $aktif = JobStore::isActiveStatus((string) ($job['status'] ?? ''));
        $redirectJob($jobId, $aktif ? 'info' : 'error', $aktif
            ? (string) ($job['pesan'] ?? 'Permintaan jeda dikirim.')
            : 'Job ' . $jobId . ' berstatus ' . (string) ($job['status'] ?? '-')
                . ' sehingga tidak bisa dijeda (hanya job yang berjalan/menunggu).');
    }

    if ($aksi === 'lanjut') {
        $job = $jobs->clearPause($jobId);

        $redirectJob($jobId, $job === null ? 'error' : 'info', $job === null
            ? 'Job tidak ditemukan: ' . $jobId
            : (string) ($job['pesan'] ?? 'Perintah lanjutkan dikirim.'));
    }

    // Aksi "ulangi": job baru dibuat dengan site + opsi yang sama, lalu halaman
    // langsung menampilkan job baru itu supaya progresnya bisa dipantau.
    $hasil = (new CrawlJobService($jobs, base_path()))->retry($jobId);

    $redirectJob($hasil['ok'] ? $hasil['job_id'] : $jobId, $hasil['ok'] ? 'info' : 'error', $hasil['ok']
        ? 'Job ' . $jobId . ' diulang sebagai ' . $hasil['job_id'] . '. ' . $hasil['pesan']
        : $hasil['pesan']);
}

// ---------------------------------------------------------------------------
// Aksi GET: cek /health bila diminta.
// ---------------------------------------------------------------------------
$health = null;
if (($_GET['act'] ?? '') === 'health') {
    $crawl = $config['crawl'] ?? [];
    $serviceConfig = $config['service'] ?? [];
    $http = new HttpClient(
        userAgent: (string) ($crawl['user_agent'] ?? 'crawler-embed/1.0'),
        connectTimeout: (int) ($serviceConfig['connect_timeout'] ?? 10),
        maxRedirects: (int) ($crawl['max_redirects'] ?? 5),
        maxBytes: (int) ($crawl['max_bytes'] ?? 5_242_880),
        verifyTls: true,
        insecureRetry: true,
        caBundle: '',
    );

    $client = new IngestServiceClient($http, $serviceConfig);
    $health = ['url' => $client->baseUrl(), 'hasil' => $client->health()];
}

$pesan = isset($_GET['pesan']) ? Text::oneLine((string) $_GET['pesan'], 400) : null;
$tipe = (string) ($_GET['tipe'] ?? 'info') === 'error' ? 'error' : 'info';

// ---------------------------------------------------------------------------
// Data untuk tampilan.
// ---------------------------------------------------------------------------
$allSites = $repository->all();
$activeSites = array_values(array_filter($allSites, static fn ($site): bool => $site->enabled));

$selectedJobId = isset($_GET['job']) && $_GET['job'] !== '' ? (string) $_GET['job'] : null;
$job = $selectedJobId !== null ? $jobs->reconcile($selectedJobId) : $jobs->latest();

if ($selectedJobId === null && is_array($job)) {
    $job = $jobs->reconcile((string) ($job['job_id'] ?? ''));
}

if ($selectedJobId !== null && $job === null) {
    $notice = ['tipe' => 'error', 'teks' => 'Job tidak ditemukan: ' . $selectedJobId];
    $job = $jobs->latest();
}

$recentJobs = $jobs->all(10);

$vectorFiles = [];
foreach (glob((string) Config::path('vectors') . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $vectorDir) {
    foreach (glob($vectorDir . DIRECTORY_SEPARATOR . '*.jsonl') ?: [] as $vectorFile) {
        $vectorFiles[] = $vectorFile;
    }
}
usort($vectorFiles, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
$lastVector = $vectorFiles[0] ?? null;

$jobStatus = is_array($job) ? (string) ($job['status'] ?? '') : '';

// Halaman menyegar sendiri selama prosesnya masih hidup (MENUNGGU/BERJALAN/
// DIJEDA) supaya perubahan status dari proses CLI -- termasuk saat job dijeda
// atau dilanjutkan -- langsung terlihat tanpa menekan refresh.
$autoRefresh = JobStore::isActiveStatus($jobStatus);

$head = $autoRefresh ? '<meta http-equiv="refresh" content="5">' : '';

$out = [];

if ($pesan !== null && $pesan !== '') {
    $out[] = '<div class="alert alert-' . ($tipe === 'error' ? 'error' : 'info') . '">' . Ui::e($pesan) . '</div>';
}

if ($notice !== null) {
    $out[] = '<div class="alert alert-' . ($notice['tipe'] === 'error' ? 'error' : 'info') . '">'
        . Ui::e($notice['teks']) . '</div>';
}

$out[] = '<h1>Crawler Web &rarr; /parse &rarr; /embed</h1>';
$out[] = '<p class="muted">Mulai crawl dari sini, pantau statusnya, lalu periksa log txt dan vektor JSONL-nya. '
    . 'Proses berjalan di latar belakang (CLI) sehingga halaman ini tidak memblokir.</p>';

// Kartu ringkasan -----------------------------------------------------------
$cards = [];
$cards[] = Ui::statCard('Site aktif', (string) count($activeSites), 'total ' . count($allSites) . ' site di config/sites.json');
$cards[] = Ui::statCard('Job tercatat', (string) count($jobs->all(1000)), 'folder ' . Ui::relPath($jobs->dir()));

if ($lastVector !== null) {
    $cards[] = Ui::statCard(
        'Vektor terakhir',
        (string) $countLines($lastVector),
        basename($lastVector) . ' - ' . Ui::bytes((int) filesize($lastVector))
    );
} else {
    $cards[] = Ui::statCard('Vektor terakhir', '0', 'belum ada berkas .jsonl');
}

$cards[] = Ui::statCard(
    'Service worker-ingest',
    (string) ($config['service']['base_url'] ?? '-'),
    '/health - /parse - /embed'
);

$out[] = '<div class="cards">' . implode('', $cards) . '</div>';

// Service /health -----------------------------------------------------------
$healthHtml = '<p class="muted">Belum diperiksa pada halaman ini. '
    . '<a href="index.php?act=health">Cek /health sekarang</a>.</p>';

if (is_array($health)) {
    /** @var \App\Service\HealthResult $result */
    $result = $health['hasil'];

    $healthHtml = '<dl class="kv">'
        . '<dt>URL</dt><dd>' . Ui::e($health['url']) . '</dd>'
        . '<dt>Status koneksi</dt><dd>' . ($result->ok ? Ui::runBadge('OK') : Ui::runBadge('GAGAL')) . '</dd>'
        . '<dt>status (dari service)</dt><dd>' . Ui::e($result->status ?? '-') . '</dd>'
        . '<dt>embedding</dt><dd>' . Ui::e($result->embedding ?? '-') . '</dd>'
        . '<dt>catatan</dt><dd>' . Ui::e($result->error ?? '-') . '</dd>'
        . '</dl>';

    if (!$result->ok) {
        $healthHtml .= '<div class="alert alert-error" style="margin-top:12px">Service tidak sehat. '
            . 'Crawl tetap bisa berjalan bila parser lokal + /embed tersedia, tetapi periksa worker-ingest pada '
            . Ui::e($health['url']) . '.</div>';
    }
}

$out[] = '<div class="panel"><h2>Status service</h2>' . $healthHtml . '</div>';

// Nilai awal form diambil dari konfigurasi (bukan angka tetap) supaya sejalan
// dengan .env + config/sites.json:
//   halaman/site   -> defaults.max_pages  (bawaan 0 = TANPA BATAS)
//   kedalaman      -> CRAWL_MAX_DEPTH     (config/app.php crawl.max_depth)
//   permintaan/run -> MAX_REQUESTS_PER_CRAWL (bawaan 0 = tanpa batas)
//   konkurensi     -> CRAWL_CONCURRENCY
$crawlConfig = is_array($config['crawl'] ?? null) ? $config['crawl'] : [];
$siteDefaults = $repository->defaults();
$webSites = array_values(array_filter(
    $activeSites,
    static fn ($site): bool => !$site->documentMode
));
// Batas halaman: 0 = tanpa batas dan itu menang atas nilai mana pun. Nilai
// terbesar hanya dipakai bila SEMUA site menulis batas eksplisit pada
// config/sites.json, supaya site yang dibiarkan tanpa batas tidak ikut dibatasi
// oleh nilai pada form.
$batasHalamanSites = array_map(static fn ($site): int => $site->maxPages, $webSites);
$defaultMaxPages = $batasHalamanSites !== [] && !in_array(0, $batasHalamanSites, true)
    ? max($batasHalamanSites)
    : max(0, (int) ($siteDefaults['max_pages'] ?? 0));
$defaultMaxDepth = $webSites !== []
    ? max(0, ...array_map(static fn ($site): int => $site->maxDepth, $webSites))
    : max(0, (int) ($siteDefaults['max_depth'] ?? ($crawlConfig['max_depth'] ?? 0)));
$defaultMaxRequests = max(0, (int) ($crawlConfig['max_requests_per_crawl'] ?? 0));
$defaultConcurrency = max(1, (int) ($crawlConfig['concurrency'] ?? 1));

$out[] = '<div class="panel"><h2>Mulai crawl</h2>'
    . DashboardView::crawlForm(
        $allSites,
        $defaultMaxPages,
        $defaultMaxDepth,
        $defaultMaxRequests,
        $defaultConcurrency,
        (bool) ($crawlConfig['follow_document_links'] ?? false),
        (bool) ($crawlConfig['follow_links'] ?? true),
        max(0, (int) ($crawlConfig['auto_retry'] ?? 0)),
    )
    . '</div>';
$out[] = '<div class="panel" id="tambah-site"><h2>Tambah web / dokumen yang diproses</h2>'
    . DashboardView::addSiteForm() . '</div>';

// Detail job ----------------------------------------------------------------
if (is_array($job)) {
    $jobId = (string) ($job['job_id'] ?? '-');
    $out[] = DashboardView::jobPanel(
        $job,
        $autoRefresh,
        (string) Config::path('logs') . DIRECTORY_SEPARATOR . 'crawl-' . date('Y-m-d') . '.txt',
        $jobs->consolePath($jobId),
    );
}

// Tabel web -----------------------------------------------------------------
if ($allSites !== []) {
    $description = 'Kolom diambil dari config/sites.json. Markdown hasil /parse dibersihkan dari menu/ornamen/footer sebelum dipecah menjadi chunk. '
        . 'Ukuran chunk bawaan ' . Ui::e((string) Config::int('chunk.size', 12000)) . ' karakter dengan overlap '
        . Ui::e((string) Config::int('chunk.overlap', 1200)) . '; field metadata kosong bernilai null.';
    $out[] = DashboardView::siteTable($allSites, $description);
}

// Tabel job terakhir --------------------------------------------------------
if ($recentJobs !== []) {
    $out[] = DashboardView::jobTable($recentJobs);
}

$body = implode(PHP_EOL, $out);

header('Content-Type: text/html; charset=utf-8');
echo Ui::layout('Dashboard', 'index', $body, $head);
