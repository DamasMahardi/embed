<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

// Seluruh keluaran probe ditahan di buffer luar supaya header() pada halaman
// yang di-render tidak memicu "headers already sent" (artefak CLI saja).
ob_start();

use App\Report\CrawlReport;
use App\Support\Config;

$report = new CrawlReport(Config::get('paths', []));
$runTerakhir = $report->latestRunId();

echo 'latestRunId = ', $runTerakhir, PHP_EOL;

$hasil = $report->summary('2026-09-22', date('Y-m-d'), '');
echo 'summary 22..hari ini: baris=', count($hasil['baris']), ' total=', json_encode($hasil['total']), PHP_EOL;

foreach ($hasil['baris'] as $row) {
    echo '  ', str_pad((string) $row['tanggal'], 12), str_pad((string) $row['site_id'], 28),
        str_pad((string) $row['dokumen'], 5), str_pad((string) $row['chunk'], 7), (string) $row['vektor'],
        '  (', (string) $row['site_name'], ')', PHP_EOL;
}

// Render dua halaman utama tanpa browser: cek fatal/warning pada output HTML.
// Hasil render dikumpulkan dulu (tanpa echo) supaya header() di index.php tidak
// memicu "headers already sent" yang menyesatkan.
$laporan = [];

foreach (['index.php', 'report.php'] as $halaman) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_GET = [];

    ob_start();
    require dirname(__DIR__) . '/public/' . $halaman;
    $html = (string) ob_get_clean();

    $laporan[] = [$halaman, strlen($html), $html];
}

foreach ($laporan as [$halaman, $panjang, $html]) {
    $masalah = [];

    foreach (['Fatal error', 'Warning:', 'Notice:', 'Undefined', 'Deprecated'] as $kata) {
        if (str_contains($html, $kata)) {
            $masalah[] = $kata;
            $pos = (int) strpos($html, $kata);
            echo '  ', $halaman, ' konteks ', $kata, ': ...',
                preg_replace('/\s+/', ' ', substr($html, max(0, $pos - 150), 400)), '...', PHP_EOL;
        }
    }

    echo str_pad($halaman, 14), 'html=', $panjang, ' masalah=', $masalah === [] ? 'tidak ada' : implode(', ', $masalah),
        ' panel_per_web=', str_contains($html, 'Per website') ? 'ADA' : 'tidak', PHP_EOL;
}

echo (string) ob_get_clean();
