<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pembaca file .env sederhana (KEY=VALUE), tanpa dependency eksternal.
 *
 * Variabel environment yang sudah ada di sistem TIDAK ditimpa oleh .env,
 * sehingga `set INGEST_SERVICE_URL=... && php bin/crawl.php crawl` tetap
 * menang atas isi .env.
 */
final class Env
{
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded || !is_file($path)) {
            self::$loaded = true;

            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($key === '') {
                continue;
            }

            // Buang tanda kutip pembungkus bila ada.
            $length = strlen($value);
            if ($length >= 2) {
                $first = $value[0];
                $last = $value[$length - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            if (getenv($key) !== false) {
                continue;
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);

        if ($value === false || $value === '') {
            return $default;
        }

        return $value;
    }

    /**
     * Apakah variabel DITULIS pada .env/lingkungan (bukan sekadar punya nilai
     * bawaan). Dipakai untuk membedakan "belum diatur" dari "diatur false",
     * mis. QDRANT_RECREATE=false tetap berarti modenya dipilih eksplisit.
     */
    public static function has(string $key): bool
    {
        $value = getenv($key);

        return $value !== false && trim($value) !== '';
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);

        if ($value === null || !is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }

    /**
     * Nilai boolean: 1/true/yes/on/ya/y dianggap true; selebihnya false.
     */
    public static function bool(string $key, bool $default): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on', 'ya', 'y'], true);
    }
}
