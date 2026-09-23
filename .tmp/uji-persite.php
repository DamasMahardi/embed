<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Report\CrawlReport;
use App\Support\Config;

$report = new CrawlReport(Config::get('paths', []));

echo 'latestRunId : ', $report->latestRunId(), PHP_EOL, PHP_EOL;

$daftar = [$report->latestRunId(), 'RUN-20260922-071155', $argv[1] ?? ''];

foreach (array_filter(array_unique($daftar)) as $runId) {
    $hasil = $report->perSiteRun($runId);

    echo '=== ', $runId, ' (', $hasil['selesai'] ? 'SELESAI' : 'BERJALAN', ') ===', PHP_EOL;
    echo str_pad('Web', 34), str_pad('Dokumen', 9), str_pad('Chunk', 8), 'Vektor', PHP_EOL;

    foreach ($hasil['baris'] as $row) {
        echo str_pad((string) $row['site'], 34), str_pad((string) $row['dokumen'], 9),
            str_pad((string) $row['chunk'], 8), (string) $row['vektor'], PHP_EOL;
    }

    echo str_pad('TOTAL', 34), str_pad((string) $hasil['total']['dokumen'], 9),
        str_pad((string) $hasil['total']['chunk'], 8), (string) $hasil['total']['vektor'], PHP_EOL, PHP_EOL;
}
