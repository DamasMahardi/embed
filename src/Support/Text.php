<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Helper teks: slug, host, perapian whitespace, deteksi charset, dan
 * pemformatan nilai untuk baris log.
 */
final class Text
{
    public static function slug(string $value, int $maxLength = 60): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        if ($value === '') {
            $value = 'halaman';
        }

        if (strlen($value) > $maxLength) {
            $value = trim(substr($value, 0, $maxLength), '-');
        }

        return $value;
    }

    public static function hostname(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : $url;
    }

    /**
     * Rapikan whitespace tanpa menghapus struktur baris markdown.
     */
    public static function normalizeWhitespace(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/[ \t]+\n/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Satu baris ringkas untuk log (buang newline, potong panjangnya).
     */
    public static function oneLine(string $text, int $maxLength = 160): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        if (mb_strlen($text) > $maxLength) {
            $text = mb_substr($text, 0, $maxLength - 3) . '...';
        }

        return $text;
    }

    /**
     * Deteksi charset dari header Content-Type atau meta tag, lalu konversi ke
     * UTF-8. Situs pemerintah sering masih memakai ISO-8859-1 / windows-1252.
     */
    public static function toUtf8(string $raw, ?string $charsetFromHeader = null): string
    {
        // Buang BOM bila ada.
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        $charset = $charsetFromHeader;

        if ($charset === null || $charset === '') {
            if (preg_match('/<meta[^>]+charset\s*=\s*["\']?\s*([a-z0-9_\-]+)/i', substr($raw, 0, 4096), $m) === 1) {
                $charset = $m[1];
            }
        }

        if ($charset === null || $charset === '') {
            $detected = mb_detect_encoding($raw, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
            $charset = $detected === false ? 'UTF-8' : $detected;
        }

        $charset = strtoupper($charset);
        if ($charset === 'UTF-8' || $charset === 'UTF8') {
            return $raw;
        }

        $converted = @mb_convert_encoding($raw, 'UTF-8', $charset);

        return $converted === false ? $raw : $converted;
    }

    /**
     * Ambil parameter charset dari header Content-Type.
     */
    public static function charsetFromContentType(?string $contentType): ?string
    {
        if ($contentType === null || $contentType === '') {
            return null;
        }

        if (preg_match('/charset\s*=\s*"?([a-z0-9_\-]+)"?/i', $contentType, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    public static function duration(float $seconds): string
    {
        return number_format($seconds, 2, '.', '') . 's';
    }

    /**
     * Durasi panjang dalam MENIT, mis. "11.84 menit".
     *
     * Dipakai pada ringkasan run (UI + log): durasi satu crawl biasanya
     * ratusan detik sehingga lebih mudah dibaca dalam menit. Nilai detiknya
     * tetap bisa ditampilkan lewat duration() sebagai rincian di belakang.
     */
    public static function durationMinutes(float $seconds, int $decimals = 2): string
    {
        return number_format($seconds / 60, $decimals, '.', '') . ' menit';
    }

    public static function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . 'B';
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1, '.', '') . 'KB';
        }

        return number_format($bytes / 1024 / 1024, 2, '.', '') . 'MB';
    }
}
