<?php

declare(strict_types=1);

/**
 * Penampil log txt + riwayat job: logs/crawl-YYYY-MM-DD.txt, logs/runs/*.txt,
 * logs/sites/*.txt, logs/terbaru.txt, konsol job (storage/jobs/*.console.txt),
 * dan berkas hasil embed (storage/vectors/*.jsonl + *.qdrant.json +
 * *.manifest.json).
 *
 * Halaman ini juga bisa mengekspor hasil embed menjadi SATU berkas JSON siap
 * kirim ke Qdrant (`?export=<.jsonl>` untuk satu berkas, `?export_all=1` untuk
 * semua yang belum/basi) memakai App\Qdrant\QdrantJsonExporter -- sumber yang
 * sama dengan ekspor otomatis pada setiap crawl.
 *
 * Hanya berkas di dalam folder logs/, storage/jobs/, dan storage/vectors/ yang
 * boleh dibaca atau diunduh (path diverifikasi memakai realpath, jadi tidak
 * bisa keluar dari folder itu).
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\Qdrant\QdrantJsonExporter;
use App\Service\CrawlJobService;
use App\Support\Config;
use App\Support\FileStorage;
use App\Support\JobStore;
use App\Support\Text;
use App\Ui\DashboardView;
use App\Ui\Ui;

$config = Config::app();
Ui::guard($config['ui'] ?? []);

$jobs = new JobStore($config['paths'] ?? []);

$logsDir = realpath((string) Config::path('logs')) ?: (string) Config::path('logs');
$runsDir = (string) Config::path('runs');
$sitesDir = (string) Config::path('sites');
$jobsDir = realpath($jobs->dir()) ?: $jobs->dir();

$vectorsDir = realpath((string) Config::path('vectors')) ?: (string) Config::path('vectors');

$allowedRoots = [$logsDir, $jobsDir, $vectorsDir];
$files = new FileStorage($allowedRoots);

/**
 * Kategori "Hapus semua" halaman ini: target => [judul, folder, pola nama
 * berkas, rekursif, anchor bagian].
 *
 * Dipakai bersama oleh penangan aksi POST dan tombol pada tiap bagian, sehingga
 * jumlah berkas yang ditampilkan pada konfirmasi selalu sama dengan yang
 * dihapus. Folder diperiksa lagi oleh FileStorage (realpath harus berada di
 * dalam logs/, storage/jobs/, atau storage/vectors/).
 *
 * @var array<string, array{judul: string, folder: string, pola: list<string>, rekursif: bool, anchor: string}>
 */
$kategoriHapus = [
    'terbaru' => [
        'judul' => 'Ringkasan run terakhir',
        'folder' => $logsDir,
        'pola' => ['terbaru.txt'],
        'rekursif' => false,
        'anchor' => 'terbaru',
    ],
    'harian' => [
        'judul' => 'Log harian',
        'folder' => $logsDir,
        'pola' => ['crawl-*.txt'],
        'rekursif' => false,
        'anchor' => 'harian',
    ],
    'runs' => [
        'judul' => 'Log per run',
        'folder' => $runsDir,
        'pola' => ['*.txt'],
        'rekursif' => false,
        'anchor' => 'runs',
    ],
    'sites' => [
        'judul' => 'Log per web',
        'folder' => $sitesDir,
        'pola' => ['*.txt'],
        'rekursif' => false,
        'anchor' => 'sites',
    ],
    'jobb' => [
        'judul' => 'Konsol job',
        'folder' => $jobsDir,
        'pola' => ['*.console.txt'],
        'rekursif' => false,
        'anchor' => 'jobb',
    ],
    'jobg' => [
        'judul' => 'Galat peluncuran job',
        'folder' => $jobsDir,
        'pola' => ['*.error.txt'],
        'rekursif' => false,
        'anchor' => 'jobg',
    ],
    'job' => [
        'judul' => 'Riwayat job (json + konsol + galat + kunci + bendera jeda)',
        'folder' => $jobsDir,
        'pola' => ['*.json', '*.txt', '*.lock', '*.pause'],
        'rekursif' => false,
        'anchor' => 'job',
    ],
    'vektor' => [
        'judul' => 'Berkas vektor (semua site & run)',
        'folder' => $vectorsDir,
        'pola' => ['*'],
        'rekursif' => true,
        'anchor' => 'vektor',
    ],
];

/**
 * Tombol "Hapus semua" satu kategori: form POST + konfirmasi yang menyebut
 * judul kategori, jumlah berkas, dan ukurannya.
 */
$tombolHapusSemua = static function (string $target) use ($kategoriHapus, $files): string {
    $kategori = $kategoriHapus[$target] ?? null;

    if ($kategori === null) {
        return '';
    }

    $hitung = $files->scanDir($kategori['folder'], $kategori['pola'], $kategori['rekursif']);

    if ($hitung['berkas'] === 0) {
        return '<span class="muted">tidak ada berkas</span>';
    }

    $pesan = 'Hapus SEMUA ' . $hitung['berkas'] . ' berkas ' . $kategori['judul']
        . ' (' . Text::humanBytes($hitung['byte']) . ')? Tindakan ini tidak bisa dibatalkan.';

    return '<form method="post" class="inline-form" onsubmit="return confirm(\''
        . Ui::e(str_replace("'", '', $pesan)) . '\')">'
        . '<input type="hidden" name="aksi" value="hapus_semua">'
        . '<input type="hidden" name="target" value="' . Ui::e($target) . '">'
        . '<button type="submit" class="danger">Hapus semua (' . $hitung['berkas'] . ' berkas, '
        . Ui::e(Text::humanBytes($hitung['byte'])) . ')</button></form>';
};

$redirect = static function (string $type, string $message, string $anchor = 'vektor'): never {
    header('Location: logs.php?tipe=' . $type . '&pesan=' . rawurlencode($message) . '#' . $anchor);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['aksi'] ?? '');

    if ($action === 'hapus_file') {
        try {
            $name = $files->delete((string) ($_POST['file'] ?? ''));
            $redirect('info', 'Berkas ' . $name . ' berhasil dihapus.');
        } catch (Throwable $exception) {
            $redirect('error', $exception->getMessage());
        }
    }

    if ($action === 'hapus_semua') {
        $target = (string) ($_POST['target'] ?? '');
        $kategori = $kategoriHapus[$target] ?? null;

        if ($kategori === null) {
            $redirect('error', 'Kategori hapus tidak dikenal.');
        }

        try {
            $hasil = $files->purgeDir($kategori['folder'], $kategori['pola'], $kategori['rekursif']);

            $pesan = $kategori['judul'] . ': ' . $hasil['berkas'] . ' berkas dihapus ('
                . Text::humanBytes($hasil['byte']) . ').';
            if ($hasil['gagal'] > 0) {
                $pesan .= ' ' . $hasil['gagal'] . ' berkas gagal dihapus (mungkin sedang dipakai proses berjalan).';
            }

            $redirect($hasil['gagal'] > 0 ? 'error' : 'info', $pesan, $kategori['anchor']);
        } catch (Throwable $exception) {
            $redirect('error', $exception->getMessage(), $kategori['anchor']);
        }
    }

    if ($action === 'hapus_run') {
        try {
            $deleted = $files->deleteVectorRun((string) ($_POST['jsonl'] ?? ''));
            $redirect('info', 'Run vector dan berkas pendamping berhasil dihapus (' . $deleted . ' berkas).');
        } catch (Throwable $exception) {
            $redirect('error', $exception->getMessage());
        }
    }

    if ($action === 'hapus_job') {
        $jobId = trim((string) ($_POST['job_id'] ?? ''));
        if ($jobId === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $jobId)) {
            $redirect('error', 'ID job tidak valid.');
        }

        $jobFiles = [
            $jobs->path($jobId),
            $jobs->consolePath($jobId),
            $jobs->errorPath($jobId),
            $jobs->pauseFlagPath($jobId),
        ];
        $deleted = 0;
        foreach ($jobFiles as $candidate) {
            if (is_file($candidate) && @unlink($candidate)) {
                $deleted++;
            }
        }

        $redirect($deleted > 0 ? 'info' : 'error', $deleted > 0
            ? 'Job ' . $jobId . ' dan berkas log job berhasil dihapus.'
            : 'Job tidak ditemukan.');
    }

    // Kendalikan job dari halaman ini juga: jeda / lanjutkan / ulangi.
    if (in_array($action, ['jeda', 'lanjut', 'ulangi'], true)) {
        $jobId = trim((string) ($_POST['job_id'] ?? ''));
        if ($jobId === '' || preg_match('/^[A-Za-z0-9_-]+$/', $jobId) !== 1) {
            $redirect('error', 'ID job tidak valid.', 'job');
        }

        if ($action === 'jeda') {
            $job = $jobs->requestPause($jobId);
            $aktif = is_array($job) && JobStore::isActiveStatus((string) ($job['status'] ?? ''));
            $redirect($job !== null && $aktif ? 'info' : 'error', $job === null
                ? 'Job tidak ditemukan: ' . $jobId
                : ($aktif
                    ? (string) ($job['pesan'] ?? 'Permintaan jeda dikirim.')
                    : 'Job ' . $jobId . ' berstatus ' . (string) ($job['status'] ?? '-') . ' sehingga tidak bisa dijeda.'), 'job');
        }

        if ($action === 'lanjut') {
            $job = $jobs->clearPause($jobId);
            $redirect($job === null ? 'error' : 'info', $job === null
                ? 'Job tidak ditemukan: ' . $jobId
                : (string) ($job['pesan'] ?? 'Perintah lanjutkan dikirim.'), 'job');
        }

        $hasil = (new CrawlJobService($jobs, base_path()))->retry($jobId);
        $redirect($hasil['ok'] ? 'info' : 'error', $hasil['ok']
            ? 'Job ' . $jobId . ' diulang sebagai ' . $hasil['job_id'] . '. ' . $hasil['pesan']
            : $hasil['pesan'], 'job');
    }
}

// ---------------------------------------------------------------------------
// Aksi ekspor: ubah .jsonl menjadi SATU berkas JSON siap kirim ke Qdrant.
//   ?export=storage/vectors/<site>/<run>.jsonl   satu berkas saja
//   ?export_all=1                                semua berkas yang belum/basi
//   &paksa=1                                     tulis ulang walau sudah ada
// Sumber ekspor sama dengan yang dipakai pipeline crawl (QdrantJsonExporter),
// jadi isi berkasnya identik dengan ekspor otomatis.
// ---------------------------------------------------------------------------
if (isset($_GET['export']) || isset($_GET['export_all'])) {
    $qdrantSettings = is_array($config['qdrant'] ?? null) ? $config['qdrant'] : [];
    $maxPoints = max(0, (int) ($qdrantSettings['export_max_points'] ?? 10000));
    $paksa = isset($_GET['paksa']);

    $targets = [];
    $exportFail = [];

    if (isset($_GET['export_all'])) {
        foreach (glob(rtrim($vectorsDir, '/\\') . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $siteDir) {
            foreach (glob($siteDir . DIRECTORY_SEPARATOR . '*.jsonl') ?: [] as $jsonl) {
                $targets[] = str_replace('\\', '/', $jsonl);
            }
        }
    } else {
        $jsonl = $files->resolve((string) ($_GET['export'] ?? ''));

        if ($jsonl === null || !str_ends_with(strtolower($jsonl), '.jsonl')) {
            $exportFail[] = 'berkas .jsonl tidak ditemukan atau di luar storage/vectors';
        } else {
            $targets[] = $jsonl;
        }
    }

    $exportOk = 0;
    $exportSkip = 0;
    $exportBytes = 0;

    foreach ($targets as $jsonl) {
        $jsonTarget = QdrantJsonExporter::defaultPath($jsonl);

        if (!$paksa && is_file($jsonTarget) && filemtime($jsonTarget) >= filemtime($jsonl)) {
            $exportSkip++;

            continue;
        }

        try {
            $stats = (new QdrantJsonExporter(
                $jsonl,
                (string) ($qdrantSettings['collection'] ?? 'documents'),
                true,
            ))->export($jsonTarget, $maxPoints);
            $exportOk++;
            $exportBytes += (int) $stats['ukuran'];
        } catch (Throwable $exception) {
            $exportFail[] = basename($jsonl) . ': ' . Text::oneLine($exception->getMessage(), 180);
        }
    }

    $pesan = 'Ekspor JSON: ' . $exportOk . ' berkas dibuat';
    if ($exportBytes > 0) {
        $pesan .= ' (' . Text::humanBytes($exportBytes) . ')';
    }
    if ($exportSkip > 0) {
        $pesan .= ', ' . $exportSkip . ' dilewati karena sudah mutakhir';
    }
    if ($exportFail !== []) {
        $pesan .= ', ' . count($exportFail) . ' gagal - ' . implode(' | ', array_slice($exportFail, 0, 3));
    }

    header('Location: logs.php?tipe=' . ($exportFail === [] ? 'info' : 'error')
        . '&pesan=' . rawurlencode($pesan) . '#vektor');
    exit;
}

$fileParam = (string) ($_GET['file'] ?? '');
$file = $fileParam === '' ? null : $files->resolve($fileParam);
$notFound = $fileParam !== '' && $file === null;

// Mode mentah: keluarkan isi berkas apa adanya (untuk diunduh/di-grep).
if ($file !== null && isset($_GET['raw'])) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: inline; filename="' . basename($file) . '"');
    readfile($file);
    exit;
}

// Mode unduh: kirim berkas sebagai lampiran (mis. .jsonl vektor / .manifest.json).
if ($file !== null && isset($_GET['download'])) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    header('Content-Length: ' . (string) filesize($file));
    readfile($file);
    exit;
}

$tail = max(20, min(20000, (int) ($_GET['tail'] ?? 300)));
$out = [];

// Pesan hasil aksi ekspor JSON (dikirim lewat redirect dari handler di atas).
$pesan = trim((string) ($_GET['pesan'] ?? ''));
if ($pesan !== '') {
    $out[] = '<div class="alert alert-' . (($_GET['tipe'] ?? '') === 'error' ? 'error' : 'info') . '">'
        . Ui::e($pesan) . '</div>';
}

if ($notFound) {
    $out[] = '<div class="alert alert-error">Berkas tidak ditemukan atau berada di luar folder log: '
        . Ui::e($fileParam) . '</div>';
}

if ($file !== null) {
    $size = (int) filesize($file);
    $maxBytes = 2 * 1024 * 1024; // batasi pembacaan untuk berkas log besar

    $handle = fopen($file, 'rb');
    $dipotong = false;
    if ($handle !== false && $size > $maxBytes) {
        fseek($handle, -$maxBytes, SEEK_END);
        $dipotong = true;
    }

    $content = $handle === false ? '' : (string) stream_get_contents($handle);
    if ($handle !== false) {
        fclose($handle);
    }

    $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
    $slice = array_slice($lines, -$tail);

    $info = Ui::bytes($size) . ' &middot; diubah ' . Ui::fileTime($file) . ' &middot; menampilkan '
        . count($slice) . ($dipotong ? ' baris terakhir (potongan 2 MB terakhir; ' : ' dari ')
        . count($lines) . ' baris';

    $out[] = '<h1>' . Ui::e(basename($file)) . '</h1>';
    $out[] = '<p class="muted">' . Ui::e(Ui::relPath($file)) . ' &middot; ' . $info . '</p>';

    $out[] = '<div class="panel">'
        . '<p style="margin-top:0"><a href="logs.php?file=' . rawurlencode(Ui::relPath($file)) . '">&laquo; daftar log</a>'
        . ' &middot; <a href="logs.php?file=' . rawurlencode(Ui::relPath($file)) . '&raw=1" target="_blank">lihat mentah</a>'
        . ' &middot; <a href="logs.php?file=' . rawurlencode(Ui::relPath($file)) . '&tail=20000">seluruh berkas</a>'
        . ' &middot; <a href="logs.php?file=' . rawurlencode(Ui::relPath($file)) . '&download=1">unduh</a>'
        . ' &middot; <form method="post" class="inline-form" onsubmit="return confirm(\'Hapus berkas ini?\')">'
        . '<input type="hidden" name="aksi" value="hapus_file"><input type="hidden" name="file" value="'
        . Ui::e(Ui::relPath($file)) . '"><button type="submit">Hapus</button></form></p>'
        . '<pre class="log">' . Ui::e(implode(PHP_EOL, $slice)) . '</pre></div>';
} else {
    $out[] = '<h1>Log &amp; Job</h1>';
    $out[] = '<p class="muted">Semua catatan crawl berbentuk txt dan bisa dibuka langsung. '
        . 'Format baris: [tanggal jam] [run] [site] [TAHAP] status=.. url=.. detail :: keterangan.</p>';
    $out[] = '<div class="alert alert-info">Setiap bagian di bawah punya tombol '
        . '<strong>Hapus semua</strong> supaya tidak perlu menghapus satu per satu: '
        . '<a href="#terbaru">ringkasan terakhir</a> &middot; <a href="#harian">log harian</a> &middot; '
        . '<a href="#runs">log per run</a> &middot; <a href="#sites">log per web</a> &middot; '
        . '<a href="#jobb">konsol job</a> &middot; <a href="#jobg">galat peluncuran job</a> &middot; '
        . '<a href="#job">riwayat job</a> &middot; <a href="#vektor">berkas vektor</a>. '
        . 'Tombol hanya menyentuh berkas di dalam folder yang ditampilkan (logs/, storage/jobs/, storage/vectors/) '
        . 'dan selalu meminta konfirmasi yang menyebut jumlah berkas + ukurannya.</div>';

    $groups = [
        'terbaru' => [
            'judul' => 'Ringkasan run terakhir (logs/terbaru.txt)',
            'files' => [$logsDir . DIRECTORY_SEPARATOR . 'terbaru.txt'],
        ],
        'harian' => [
            'judul' => 'Log harian (logs/)',
            'files' => Ui::textFiles($logsDir, 'crawl-*.txt', 30),
        ],
        'runs' => [
            'judul' => 'Log per run (logs/runs/)',
            'files' => Ui::textFiles($runsDir, '*.txt', 40),
        ],
        'sites' => [
            'judul' => 'Log per web (logs/sites/)',
            'files' => Ui::textFiles($sitesDir, '*.txt', 40),
        ],
        'jobb' => [
            'judul' => 'Konsol job (storage/jobs/)',
            'files' => Ui::textFiles($jobsDir, '*.console.txt', 20),
        ],
        'jobg' => [
            'judul' => 'Galat peluncuran job (storage/jobs/)',
            'files' => Ui::textFiles($jobsDir, '*.error.txt', 20),
        ],
    ];

    foreach ($groups as $target => $group) {
        $files = array_values(array_filter($group['files'], static fn (string $path): bool => is_file($path)));

        if ($files === []) {
            continue;
        }

        $items = '';
        foreach ($files as $path) {
            $items .= '<li><span>' . Ui::logLink($path) . '</span>'
                . '<span class="muted">' . Ui::bytes((int) filesize($path)) . ' &middot; ' . Ui::fileTime($path)
                . ' &middot; <form method="post" class="inline-form" onsubmit="return confirm(\'Hapus berkas ini?\')">'
                . '<input type="hidden" name="aksi" value="hapus_file"><input type="hidden" name="file" value="'
                . Ui::e(Ui::relPath($path)) . '"><button type="submit">Hapus</button></form></span></li>';
        }

        $out[] = '<div class="panel" id="' . Ui::e($target) . '">'
            . '<div class="panel-head"><h2>' . Ui::e($group['judul']) . '</h2>'
            . $tombolHapusSemua($target) . '</div>'
            . '<ul class="file-list">' . $items . '</ul></div>';
    }

    // Riwayat job (berkas JSON pada storage/jobs) ----------------------------
    $jobRows = '';
    foreach ($jobs->all(50) as $job) {
        $jobId = (string) ($job['job_id'] ?? '-');
        $ringkasan = is_array($job['ringkasan'] ?? null) ? $job['ringkasan'] : [];
        $console = $jobs->consolePath($jobId);

        $jobRows .= '<tr>'
            . '<td>' . Ui::e($jobId) . '</td>'
            . '<td>' . Ui::statusBadge((string) ($job['status'] ?? '-')) . '</td>'
            . '<td>' . Ui::e((string) ($job['dibuat'] ?? '-')) . '</td>'
            . '<td>' . Ui::e((string) ($job['selesai'] ?? '-')) . '</td>'
            . '<td>' . Ui::e(Ui::value($ringkasan, 'documents')) . '</td>'
            . '<td>' . Ui::e(Ui::value($ringkasan, 'chunks')) . '</td>'
            . '<td>' . Ui::e(Ui::value($ringkasan, 'embedded')) . '</td>'
            . '<td>' . (is_file((string) ($job['log_run'] ?? '')) ? Ui::logLink((string) $job['log_run'], 'run') : '-') . '</td>'
            . '<td>' . (is_file($console) ? Ui::logLink($console, 'konsol') : '-') . '</td>'
            . '<td><a href="index.php?job=' . rawurlencode($jobId) . '">detail</a>'
            . ' &middot; <form method="post" class="inline-form" onsubmit="return confirm(\'Hapus job dan log job ini?\')">'
            . '<input type="hidden" name="aksi" value="hapus_job"><input type="hidden" name="job_id" value="'
            . Ui::e($jobId) . '"><button type="submit">Hapus</button></form>'
            . DashboardView::jobActions($job, 'logs.php') . '</td>'
            . '</tr>';
    }

    $out[] = '<div class="panel" id="job">'
        . '<div class="panel-head"><h2>Riwayat job (storage/jobs)</h2>' . $tombolHapusSemua('job') . '</div>'
        . ($jobRows === ''
            ? '<p class="muted">Belum ada job. Mulai crawl dari <a href="index.php">dashboard</a>.</p>'
            : '<table><thead><tr><th>Job ID</th><th>Status</th><th>Dibuat</th><th>Selesai</th><th>Dokumen</th>'
              . '<th>Chunk</th><th>Vektor</th><th>Log run</th><th>Konsol</th><th></th></tr></thead><tbody>'
              . $jobRows . '</tbody></table>')
        . '</div>';

    // Berkas vektor ----------------------------------------------------------
    // Ditampilkan di halaman ini supaya hasil embed bisa diperiksa, diekspor ke
    // JSON siap Qdrant, dan diunduh langsung dari browser (lihat / mentah /
    // unduh), tanpa membuka explorer.
    $qdrantSettings = is_array($config['qdrant'] ?? null) ? $config['qdrant'] : [];
    $collectionUrl = rtrim((string) ($qdrantSettings['base_url'] ?? 'http://localhost:6333'), '/')
        . '/collections/' . (string) ($qdrantSettings['collection'] ?? 'documents');

    $contohKirim = 'Invoke-RestMethod -Method Put -ContentType "application/json" -Uri "'
        . $collectionUrl . '/points?wait=true" -InFile storage\vectors\<site>\<run>.qdrant.json';

    $vectorRows = '';
    $jumlahJson = 0;
    $jumlahBasi = 0;
    $jumlahJsonl = 0;

    /** Tautan aksi standar untuk satu berkas (lihat / mentah / unduh). */
    $fileActions = static function (string $path): string {
        $rel = rawurlencode(Ui::relPath($path));

        return '<a href="logs.php?file=' . $rel . '">lihat</a>'
            . ' &middot; <a href="logs.php?file=' . $rel . '&raw=1" target="_blank">mentah</a>'
            . ' &middot; <a href="logs.php?file=' . $rel . '&download=1">unduh</a>'
            . ' &middot; <form method="post" class="inline-form" onsubmit="return confirm(\'Hapus berkas ini?\')">'
            . '<input type="hidden" name="aksi" value="hapus_file"><input type="hidden" name="file" value="'
            . Ui::e(Ui::relPath($path)) . '"><button type="submit">Hapus</button></form>';
    };

    foreach (glob((string) Config::path('vectors') . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $vectorDir) {
        // Kelompokkan berkas per run: RUN-....jsonl + .qdrant.json + .manifest.json
        // ditampilkan sebagai SATU baris supaya mudah dibandingkan.
        $runs = [];
        $patterns = ['jsonl' => '*.jsonl', 'qdrant' => '*.qdrant.json', 'manifest' => '*.manifest.json'];

        foreach ($patterns as $kind => $pattern) {
            foreach (glob(rtrim($vectorDir, '/\\') . DIRECTORY_SEPARATOR . $pattern) ?: [] as $path) {
                $base = match ($kind) {
                    'qdrant' => preg_replace('/\.qdrant\.json$/i', '', $path),
                    'manifest' => preg_replace('/\.manifest\.json$/i', '', $path),
                    default => preg_replace('/\.jsonl$/i', '', $path),
                };

                $runs[(string) $base][$kind] = str_replace('\\', '/', $path);
            }
        }

        $entries = [];
        foreach ($runs as $base => $files) {
            $mtime = 0;
            foreach ($files as $path) {
                $mtime = max($mtime, (int) filemtime($path));
            }
            $entries[] = ['base' => (string) $base, 'files' => $files, 'mtime' => $mtime];
        }

        usort($entries, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

        foreach (array_slice($entries, 0, 10) as $entry) {
            $files = $entry['files'];
            $jsonl = $files['jsonl'] ?? null;
            $json = $files['qdrant'] ?? null;
            $manifest = $files['manifest'] ?? null;

            $vektorCell = $jsonl === null
                ? '<span class="muted">-</span>'
                : Ui::bytes((int) filesize($jsonl)) . '<br>' . $fileActions($jsonl);

            if ($jsonl !== null) {
                $jumlahJsonl++;
            }

            if ($json === null) {
                $jsonCell = ($jsonl === null ? '' : '<span class="badge warn">belum</span><br>')
                    . ($jsonl === null
                        ? '<span class="muted">-</span>'
                        : '<a href="logs.php?export=' . rawurlencode(Ui::relPath($jsonl)) . '">ekspor json</a>');
            } else {
                $jumlahJson++;
                $basi = $jsonl !== null && filemtime($json) < filemtime($jsonl);

                if ($basi) {
                    $jumlahBasi++;
                }

                $jsonCell = Ui::bytes((int) filesize($json)) . ' '
                    . ($basi ? '<span class="badge warn">basi</span>' : '<span class="badge ok">siap</span>')
                    . '<br>' . $fileActions($json);
            }

            $aksi = $jsonl === null
                ? '-'
                : '<a href="logs.php?export=' . rawurlencode(Ui::relPath($jsonl)) . '">'
                                    . ($json === null ? 'ekspor json' : 'ekspor ulang') . '</a>'
                                    . ' &middot; <form method="post" class="inline-form" onsubmit="return confirm(\'Hapus seluruh run vector?\')">'
                                    . '<input type="hidden" name="aksi" value="hapus_run"><input type="hidden" name="jsonl" value="'
                                    . Ui::e(Ui::relPath($jsonl ?? '')) . '"><button type="submit">Hapus run</button></form>';

            $vectorRows .= '<tr><td>' . Ui::e(basename(dirname($entry['base']))) . '</td>'
                . '<td class="mono">' . Ui::e(basename($entry['base'])) . '</td>'
                . '<td>' . $vektorCell . '</td>'
                . '<td>' . $jsonCell . '</td>'
                . '<td>' . ($manifest === null ? '<span class="muted">-</span>' : $fileActions($manifest)) . '</td>'
                . '<td>' . Ui::fileTime($jsonl ?? $json ?? $manifest ?? '') . '</td>'
                . '<td>' . $aksi . '</td></tr>';
        }
    }

    $keterangan = $jumlahJson === 0
        ? 'Belum ada berkas <code>.qdrant.json</code>: tekan <em>Ekspor semua yang belum</em> untuk '
          . 'mengubah tiap <code>.jsonl</code> menjadi satu berkas JSON siap kirim.'
        : number_format($jumlahJson, 0, ',', '.') . ' berkas <code>.qdrant.json</code> siap kirim dari '
          . number_format($jumlahJsonl, 0, ',', '.') . ' run'
          . ($jumlahBasi > 0 ? ' &middot; ' . $jumlahBasi . ' <span class="badge warn">basi</span> (tekan ekspor ulang)' : '');

    $out[] = '<div class="panel" id="vektor">'
        . '<div class="panel-head"><h2>Berkas vektor (storage/vectors)</h2>' . $tombolHapusSemua('vektor') . '</div>'
        . '<p class="muted">Setiap run menghasilkan tiga berkas: <code>.jsonl</code> (1 baris = 1 chunk + '
        . 'embedding, ditulis bertahap saat crawl berjalan), <code>.qdrant.json</code> (semuanya digabung '
        . 'menjadi envelope <code>{"collection_name":...,"wait":true,"points":[...]}</code> &mdash; '
        . '<strong>berkas inilah yang dikirim ke Qdrant</strong>), '
        . 'dan <code>.manifest.json</code> (ringkasan run, bukan untuk Qdrant). Berkas '
        . '<code>.qdrant.json</code> dibuat otomatis begitu crawl selesai; tombol di bawah dipakai untuk '
        . 'run lama, berkas yang terhapus, atau berkas yang basi.</p>'
        . '<p class="muted">Isi berkasnya persis badan permintaan Qdrant, jadi cukup sekali unggah: '
        . '<code>' . Ui::e($contohKirim) . '</code> &mdash; di curl: '
        . '<code>curl -X PUT -H "Content-Type: application/json" --data-binary @storage/vectors/&lt;site&gt;/&lt;run&gt;.qdrant.json "'
        . Ui::e($collectionUrl) . '/points?wait=true"</code>. Untuk run besar (di atas '
        . Ui::e((string) ($qdrantSettings['export_max_points'] ?? 10000)) . ' point) pakai unggahan '
        . 'bertahap: <code>php bin/crawl.php qdrant --create --batch=64</code>. Uji tanpa mengirim: '
        . 'tambahkan <code>--dry-run</code>.</p>'
        . '<p class="muted">' . $keterangan . '</p>'
        . '<div class="actions">'
        . '<a class="btn-link" href="logs.php?export_all=1">Ekspor semua yang belum</a>'
        . '<a class="btn-link secondary" href="logs.php?export_all=1&paksa=1">Tulis ulang semua JSON</a>'
        . '</div>'
        . ($vectorRows === ''
            ? '<p class="muted">Belum ada berkas .jsonl. Jalankan crawl terlebih dahulu.</p>'
            : '<table><thead><tr><th>Site</th><th>Run</th><th>Vektor (.jsonl)</th>'
              . '<th>JSON siap Qdrant</th><th>Ringkasan</th><th>Diubah</th><th>Aksi</th></tr></thead><tbody>'
              . $vectorRows . '</tbody></table>')
        . '</div>';
}

header('Content-Type: text/html; charset=utf-8');
echo Ui::layout('Log & Job', 'logs', implode(PHP_EOL, $out));
