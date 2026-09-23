<?php

declare(strict_types=1);

namespace App\Ui;

use App\Support\Config;
use App\Support\JobStore;
use App\Support\Text;

/**
 * Helper tampilan untuk halaman UI (public/index.php, public/logs.php).
 *
 * Sengaja tanpa framework/template engine: halaman hanya menyusun HTML dari
 * helper kecil di sini supaya tampilan konsisten dan mudah dibaca.
 */
final class Ui
{
    /**
     * Pastikan UI aktif dan (opsional) hanya bisa diakses dari localhost.
     *
     * @param array<string, mixed> $settings bagian "ui" pada config/app.php
     */
    public static function guard(array $settings): void
    {
        if (!(bool) ($settings['enabled'] ?? true)) {
            self::reject('UI dimatikan (UI_ENABLED=false pada .env).');
        }

        if (!(bool) ($settings['allow_localhost_only'] ?? true)) {
            return;
        }

        $address = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        if (!in_array($address, ['127.0.0.1', '::1', ''], true)) {
            self::reject('UI hanya boleh dibuka dari localhost (UI_LOCALHOST_ONLY=true).');
        }
    }

    public static function reject(string $message): void
    {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo self::layout('Akses ditolak', '', '<div class="alert alert-error">' . self::e($message) . '</div>');
        exit;
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Bungkus isi halaman dengan layout + CSS inline.
     */
    public static function layout(string $title, string $active, string $body, string $headExtra = ''): string
    {
        $appName = (string) Config::get('app.name', 'Crawler Web -> Parse -> Embed');

        $nav = [
            'index' => ['Dashboard', 'index.php'],
            'logs' => ['Log & Job', 'logs.php'],
        ];

        $menu = '';
        foreach ($nav as $key => [$label, $href]) {
            $menu .= sprintf(
                '<a class="nav-link%s" href="%s">%s</a>',
                $key === $active ? ' nav-link-active' : '',
                self::e($href),
                self::e($label)
            );
        }

        return '<!doctype html>' . PHP_EOL
            . '<html lang="id"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . self::e($title) . ' | Crawler Embed</title>'
            . $headExtra
            . '<style>' . self::css() . '</style>'
            . '</head><body>'
            . '<header class="topbar"><div class="wrap">'
            . '<div class="brand">Crawler Embed <span class="muted">' . self::e($appName) . '</span></div>'
            . '<nav>' . $menu . '</nav>'
            . '</div></header>'
            . '<main class="wrap">' . $body . '</main>'
            . '<footer class="wrap muted">Lokasi data: storage/html &middot; storage/markdown &middot; '
            . 'storage/vectors &middot; storage/jobs &middot; logs/*.txt<br>'
            . 'Waktu: ' . self::e((string) date_default_timezone_get())
            . ' (' . self::e(date('T P')) . ') &middot; sekarang ' . self::e(date('Y-m-d H:i:s')) . '</footer>'
            . '</body></html>';
    }

    /**
     * Kartu ringkasan angka.
     */
    public static function statCard(string $label, string $value, string $hint = ''): string
    {
        return '<div class="card stat"><div class="stat-label">' . self::e($label) . '</div>'
            . '<div class="stat-value">' . self::e($value) . '</div>'
            . ($hint === '' ? '' : '<div class="stat-hint muted">' . self::e($hint) . '</div>')
            . '</div>';
    }

    /**
     * Lencana status job (MENUNGGU/BERJALAN/DIJEDA/SELESAI/GAGAL).
     */
    public static function statusBadge(string $status): string
    {
        $class = match (strtoupper($status)) {
            JobStore::STATUS_DONE => 'badge ok',
            JobStore::STATUS_RUNNING => 'badge run',
            JobStore::STATUS_PAUSED => 'badge warn',
            JobStore::STATUS_FAILED => 'badge bad',
            default => 'badge idle',
        };

        return '<span class="' . $class . '">' . self::e($status) . '</span>';
    }

    /**
     * Lencana status RunResult (SUKSES / SUKSES_SEBAGIAN / GAGAL).
     */
    public static function runBadge(?string $status): string
    {
        if ($status === null || $status === '') {
            return '<span class="badge idle">-</span>';
        }

        $class = match (strtoupper($status)) {
            'SUKSES', 'OK', 'AKTIF' => 'badge ok',
            'SUKSES_SEBAGIAN', 'SEBAGIAN' => 'badge warn',
            'GAGAL', 'FAILED', 'NONAKTIF' => 'badge bad',
            default => 'badge idle',
        };

        return '<span class="' . $class . '">' . self::e($status) . '</span>';
    }

    /**
     * Ambil nilai dari array (job/ringkasan) sebagai teks aman.
     *
     * @param array<string, mixed> $row
     */
    public static function value(array $row, string $key, mixed $default = '-'): string
    {
        $value = $row[$key] ?? $default;

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
        }

        if (is_bool($value)) {
            return $value ? 'ya' : 'tidak';
        }

        return $value === null ? '-' : (string) $value;
    }

    public static function bytes(int $bytes): string
    {
        return Text::humanBytes($bytes);
    }

    public static function duration(float $seconds): string
    {
        return Text::duration($seconds);
    }

    /**
     * Durasi run dalam menit untuk panel "Ringkasan run".
     */
    public static function durationMinutes(float $seconds): string
    {
        return Text::durationMinutes($seconds);
    }

    /**
     * Format waktu modifikasi berkas untuk tampilan.
     */
    public static function fileTime(string $file): string
    {
        $time = is_file($file) ? filemtime($file) : false;

        return $time === false ? '-' : date('Y-m-d H:i:s', $time);
    }

    /**
     * Daftar berkas txt pada satu direktori (terbaru dulu).
     *
     * @return list<string>
     */
    public static function textFiles(string $dir, string $pattern = '*.txt', int $limit = 60): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob(rtrim($dir, "/\\") . DIRECTORY_SEPARATOR . $pattern) ?: [];
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return array_slice($files, 0, $limit);
    }

    /**
     * Path relatif terhadap root project (dipakai untuk tautan lintas halaman).
     */
    public static function relPath(string $path): string
    {
        $base = str_replace('\\', '/', base_path());
        $target = str_replace('\\', '/', $path);

        if (str_starts_with($target, $base)) {
            $target = ltrim(substr($target, strlen($base)), '/');
        }

        return $target;
    }

    /**
     * Tautan ke penampil log (public/logs.php).
     */
    public static function logLink(string $file, ?string $label = null, string $class = ''): string
    {
        return sprintf(
            '<a%s href="logs.php?file=%s">%s</a>',
            $class === '' ? '' : ' class="' . self::e($class) . '"',
            rawurlencode(self::relPath($file)),
            self::e($label ?? basename($file))
        );
    }

    private static function css(): string
    {
        return <<<'CSS'
body { margin: 0; font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
  background: #f4f6fb; color: #1b2333; font-size: 14px; line-height: 1.5; }
.topbar { background: #16213e; color: #fff; }
.wrap { max-width: 1180px; margin: 0 auto; padding: 16px 20px; }
.topbar .wrap { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.brand { font-weight: 600; font-size: 16px; }
.brand .muted { color: #9fb0d0; font-weight: 400; font-size: 12px; margin-left: 6px; }
nav { display: flex; gap: 8px; }
.nav-link { color: #cdd8ee; text-decoration: none; padding: 6px 12px; border-radius: 6px; font-size: 13px; }
.nav-link:hover { background: rgba(255,255,255,.12); }
.nav-link-active { background: #2f5d9e; color: #fff; }
main { padding-top: 20px; padding-bottom: 40px; }
footer { font-size: 12px; padding-bottom: 24px; }
h1 { font-size: 20px; margin: 0 0 4px; }
h2 { font-size: 15px; margin: 0 0 12px; text-transform: uppercase; letter-spacing: .04em; color: #43526b; }
.muted { color: #6b7a95; }
.cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 20px; }
.card { background: #fff; border: 1px solid #e2e7f1; border-radius: 10px; padding: 14px 16px; }
.stat-label { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: #6b7a95; }
.stat-value { font-size: 20px; font-weight: 600; margin-top: 4px; }
.stat-hint { font-size: 12px; margin-top: 4px; }
.panel { background: #fff; border: 1px solid #e2e7f1; border-radius: 10px; padding: 16px 18px; margin-bottom: 20px; }
.panel-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
.panel-head h2 { margin: 0; }
.inline-form { display: inline; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eef1f7; vertical-align: top; }
th { font-size: 12px; text-transform: uppercase; letter-spacing: .03em; color: #6b7a95; }
tbody tr:hover { background: #f8faff; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
.badge.ok { background: #e2f6e9; color: #14663a; }
.badge.bad { background: #fde8e8; color: #9b1c1c; }
.badge.run { background: #e6efff; color: #1b4592; }
.badge.warn { background: #fff3dc; color: #8a5a00; }
.badge.idle { background: #eef1f7; color: #4b5a75; }
label { display: block; font-size: 12px; font-weight: 600; color: #43526b; margin-bottom: 4px; }
input[type=text], input[type=number], select { width: 100%; padding: 7px 9px;
  border: 1px solid #ccd4e4; border-radius: 6px; font: inherit; background: #fff; }
.grid-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px 14px; }
.form-row { margin-bottom: 14px; }
.check { display: flex; align-items: center; gap: 6px; font-size: 13px; }
.checks { display: flex; flex-wrap: wrap; gap: 8px 18px; }
button { background: #2f5d9e; color: #fff; border: 0; border-radius: 6px; padding: 9px 16px;
  font: inherit; font-weight: 600; cursor: pointer; }
button:hover { background: #274e86; }
button.secondary { background: #e8ecf5; color: #29344a; }
button.secondary:hover { background: #dbe2ef; }
button.danger { background: #b3261e; }
button.danger:hover { background: #8f1e17; }
.alert { border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; font-size: 13px; }
.alert-info { background: #e6efff; color: #1b4592; }
.alert-error { background: #fde8e8; color: #9b1c1c; }
pre.log { background: #101827; color: #dbe4f5; padding: 14px; border-radius: 8px; overflow: auto;
  max-height: 60vh; font-family: Consolas, "Courier New", monospace; font-size: 12.5px; margin: 0; }
.kv { display: grid; grid-template-columns: 230px 1fr; gap: 4px 12px; font-size: 13px; margin: 0; }
.kv dt { color: #6b7a95; }
.kv dd { margin: 0; word-break: break-word; }
.file-list { list-style: none; margin: 0; padding: 0; font-size: 13px; }
.file-list li { padding: 6px 0; border-bottom: 1px solid #eef1f7; display: flex; gap: 10px; justify-content: space-between; }
.file-list a { color: #2f5d9e; text-decoration: none; }
.file-list a:hover { text-decoration: underline; }
.mono { font-family: Consolas, "Courier New", monospace; font-size: 12px; }
.btn-link { display: inline-block; background: #2f5d9e; color: #fff; text-decoration: none;
  padding: 7px 12px; border-radius: 6px; font-size: 13px; font-weight: 600; }
.btn-link:hover { background: #274e86; }
.btn-link.secondary { background: #e8ecf5; color: #29344a; }
.btn-link.secondary:hover { background: #dbe2ef; }
.actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 10px; }
CSS;
    }
}
