<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Storage\CrawlSiteStore;
use App\Support\Config;

try {
    $store = new CrawlSiteStore(Config::get('mysql', []));

    echo 'MYSQL_OK  ', Config::get('mysql')['host'] ?? '?', ':', Config::get('mysql')['port'] ?? '?',
        ' db=', Config::get('mysql')['database'] ?? '?', PHP_EOL;
    echo 'Tabel crawl_sites  : ', (int) $store->pdo()->query('SELECT COUNT(*) FROM crawl_sites')->fetchColumn(), ' baris', PHP_EOL;
    echo 'Tabel discovery_log:', (int) $store->pdo()->query('SELECT COUNT(*) FROM discovery_logs')->fetchColumn(), ' baris', PHP_EOL;

    foreach ($store->stats() as $kunci => $nilai) {
        echo '  ', $kunci, ' = ', is_scalar($nilai) ? (string) $nilai : json_encode($nilai), PHP_EOL;
    }

    foreach (array_slice($store->list('', 10), 0, 10) as $row) {
        echo '  - ', ($row['status'] ?? '?'), ' ', ($row['url'] ?? ''), PHP_EOL;
    }
} catch (Throwable $e) {
    echo 'MYSQL_GAGAL: ', $e->getMessage(), PHP_EOL;
}
