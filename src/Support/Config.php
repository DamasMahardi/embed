<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Config aplikasi dari config/app.php dengan akses dot-notation:
 * Config::get('service.base_url').
 */
final class Config
{
    /** @var array<string, mixed>|null */
    private static ?array $app = null;

    /** @var array<string, mixed>|null */
    private static ?array $sitesRaw = null;

    /** @return array<string, mixed> */
    public static function app(): array
    {
        if (self::$app === null) {
            $file = base_path('config/app.php');
            if (!is_file($file)) {
                throw new RuntimeException('File konfigurasi tidak ditemukan: ' . $file);
            }

            /** @var array<string, mixed> $config */
            $config = require $file;
            self::$app = $config;
        }

        return self::$app;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::app();

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default);

        return is_bool($value) ? $value : (bool) $value;
    }

    /**
     * Isi mentah config/sites.json (bagian "defaults" dan "sites").
     *
     * BOM UTF-8 di awal berkas (ditulis oleh Notepad / VS Code "UTF-8 with
     * BOM") dibuang lebih dulu: BOM membuat json_decode gagal dengan pesan
     * "Syntax error" walaupun isi berkas benar.
     *
     * @return array<string, mixed>
     */
    public static function sitesRaw(): array
    {
        if (self::$sitesRaw === null) {
            $file = self::string('paths.sites_config', base_path('config/sites.json'));
            if (!is_file($file)) {
                throw new RuntimeException('File daftar situs tidak ditemukan: ' . $file);
            }

            $mentah = (string) file_get_contents($file);
            $decoded = json_decode(self::stripBom($mentah), true);

            if (!is_array($decoded)) {
                throw new RuntimeException(
                    'config/sites.json bukan JSON yang valid: ' . json_last_error_msg()
                    . self::jsonHint($mentah) . '. Berkas: ' . $file
                );
            }

            self::$sitesRaw = $decoded;
        }

        return self::$sitesRaw;
    }

    /**
     * Buang BOM UTF-8 di awal teks (bila ada).
     */
    public static function stripBom(string $teks): string
    {
        return strncmp($teks, "\xEF\xBB\xBF", 3) === 0 ? substr($teks, 3) : $teks;
    }

    /**
     * Tebakan penyebab JSON gagal di-decode supaya berkas mudah diperbaiki.
     */
    private static function jsonHint(string $json): string
    {
        $tanda = [];

        if (strncmp($json, "\xEF\xBB\xBF", 3) === 0) {
            $tanda[] = 'BOM UTF-8 di awal berkas (simpan ulang sebagai UTF-8 tanpa BOM)';
        }

        if (preg_match('/(,|\[|\{)\s*[\]\}]/', $json) === 1) {
            $tanda[] = 'koma berlebih sebelum ] atau }';
        }

        if (preg_match('#^\s*(//|/\*)#m', $json) === 1) {
            $tanda[] = 'komentar (// atau /* */) yang tidak dikenal JSON';
        }

        if (preg_match("/'[^'\n]*'\s*:/", $json) === 1) {
            $tanda[] = "tanda kutip tunggal (JSON memakai kutip ganda)";
        }

        return ' => kemungkinan penyebab: ' . ($tanda === [] ? 'sintaks berkas (koma/kurung/kutip) tidak lengkap' : implode('; ', $tanda));
    }

    /**
     * Path absolut yang dijamin ada (dibuat bila belum ada).
     */
    public static function path(string $key, bool $create = true): string
    {
        $path = self::string('paths.' . $key);

        if ($path === '') {
            throw new RuntimeException('Path konfigurasi tidak dikenal: paths.' . $key);
        }

        return $create ? ensure_dir($path) : $path;
    }
}
