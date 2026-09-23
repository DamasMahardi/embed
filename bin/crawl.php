<?php

declare(strict_types=1);

/**
 * Titik masuk CLI crawler-web-embed.
 *
 * Pemakaian:
 *   php bin/crawl.php health
 *   php bin/crawl.php list
 *   php bin/crawl.php crawl [site ...] [opsi]
 *   php bin/crawl.php logs [--tail=50]
 *
 * Jalur ini TIDAK butuh web server: crawler mengunduh HTML sendiri lewat curl,
 * lalu mengirim berkas HTML ke POST /parse dan teks chunk ke POST /embed pada
 * worker-ingest (FastAPI).
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\Console\Application;
use App\Support\Config;

$config = Config::app();

// Log fatal error dari PHP tetap terlihat di terminal, bukan cuma di log web.
ini_set('display_errors', '1');
error_reporting(E_ALL);

exit((new Application($config))->run($argv));
