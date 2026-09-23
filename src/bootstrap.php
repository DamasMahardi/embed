<?php

declare(strict_types=1);

/**
 * Bootstrap aplikasi: autoloader PSR-4 (App\ -> src/), pemuat .env, dan helper
 * path. Tidak ada dependency eksternal, jadi `php bin/crawl.php` langsung
 * berjalan. Bila `composer install` pernah dijalankan, vendor/autoload.php
 * tetap dipakai (untuk kompatibilitas ke depan).
 */

if (!defined('APP_BASE_PATH')) {
    define('APP_BASE_PATH', dirname(__DIR__));
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

if (is_file(APP_BASE_PATH . '/vendor/autoload.php')) {
    require_once APP_BASE_PATH . '/vendor/autoload.php';
}

App\Support\Env::load(APP_BASE_PATH . '/.env');

/*
 * Zona waktu PHP untuk SELURUH stempel waktu aplikasi: log txt (Logger),
 * berkas job (JobStore), ringkasan run, dan kolom waktu pada UI
 * (Ui::fileTime). Tanpa baris ini date() mengikuti zona waktu php.ini
 * (umumnya UTC) sehingga jam pada log/UI tampak 7 jam lebih awal dari WIB.
 *
 * Ubah lewat APP_TIMEZONE pada .env (Asia/Jakarta, Asia/Makassar, Asia/Jayapura).
 */
$appTimezone = (string) (App\Support\Env::get('APP_TIMEZONE', 'Asia/Jakarta') ?? 'Asia/Jakarta');

if (!in_array($appTimezone, timezone_identifiers_list(), true)) {
    $appTimezone = 'Asia/Jakarta';
}

date_default_timezone_set($appTimezone);

if (!function_exists('base_path')) {
    /**
     * Path absolut relatif terhadap root project.
     */
    function base_path(string $relative = ''): string
    {
        $relative = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative), DIRECTORY_SEPARATOR);

        return $relative === '' ? APP_BASE_PATH : APP_BASE_PATH . DIRECTORY_SEPARATOR . $relative;
    }
}

if (!function_exists('ensure_dir')) {
    /**
     * Membuat direktori (rekursif) bila belum ada.
     */
    function ensure_dir(string $path): string
    {
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }

        return $path;
    }
}
