<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Menjalankan proses crawl di latar belakang dari UI web.
 *
 * UI tidak boleh memblokir permintaan HTTP selama crawl berjalan. Proses
 * `php bin/crawl.php crawl ...` dibuat dengan proc_open() tanpa shell dan
 * keluarannya dialihkan ke storage/jobs/<job_id>.console.txt (stderr ke
 * <job_id>.error.txt). Handle proses sengaja TIDAK ditutup (proc_close tidak
 * pernah dipanggil) sehingga induk selesai dalam hitungan milidetik, sedangkan
 * proses crawl tetap berjalan sampai finish walaupun induknya sudah selesai.
 *
 * Catatan: `exec()` + PowerShell `Start-Process` juga bisa dipakai, tetapi
 * exec() menunggu pipa stdout tertutup sehingga menahan permintaan HTTP sampai
 * crawl selesai (terukur ~6,8 s untuk crawl 1 halaman). proc_open tanpa shell
 * hanya membutuhkan ~0,1 s.
 */
final class JobLauncher
{
    /**
     * Proses yang sudah dilepas. Referensinya disimpan agar tidak dibersihkan
     * pengumpul sampah PHP (yang akan memanggil proc_close dan menunggu).
     *
     * @var list<resource>
     */
    private static array $detached = [];

    /**
     * Jalankan CLI crawler untuk sebuah job.
     *
     * @param list<string> $arguments argumen setelah "crawl"
     *
     * @return array{ok: bool, command: string, pesan: string, pid: int}
     */
    public static function launch(string $basePath, array $arguments, string $consoleLog, string $errorLog): array
    {
        $script = $basePath . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'crawl.php';

        if (!is_file($script)) {
            return [
                'ok' => false,
                'command' => '',
                'pesan' => 'Skrip CLI tidak ditemukan: ' . $script,
                'pid' => 0,
            ];
        }

        ensure_dir(dirname($consoleLog));
        ensure_dir(dirname($errorLog));

        $command = array_merge([self::phpBinary(), $script], $arguments);
        $descriptors = self::descriptors($consoleLog, $errorLog);

        $process = @proc_open(
            $command,
            $descriptors,
            $pipes,
            $basePath,
            null,
            ['bypass_shell' => true],
        );

        if (!is_resource($process)) {
            // Cadangan: lewat shell. Pengalihan ditulis manual supaya anak tetap
            // menulis ke berkas, bukan ke pipa milik proses induk.
            $line = self::commandLine($command)
                . ' > ' . self::quote($consoleLog)
                . ' 2> ' . self::quote($errorLog)
                . ' < ' . self::quote(self::nullDevice());

            $process = @proc_open($line, $descriptors, $pipes, $basePath, null, []);
        }

        if (!is_resource($process)) {
            return [
                'ok' => false,
                'command' => self::commandLine($command),
                'pesan' => 'Gagal melepas proses: proc_open menolak perintah.',
                'pid' => 0,
            ];
        }

        $status = proc_get_status($process);
        $pid = (int) ($status['pid'] ?? 0);

        // Ditahan tanpa proc_close: anak hidup terus, induk tidak menunggu.
        self::$detached[] = $process;

        return [
            'ok' => true,
            'command' => self::commandLine($command) . ' > ' . $consoleLog . ' 2> ' . $errorLog,
            'pesan' => 'Proses crawl dilepas ke latar belakang (pid ' . $pid . ').',
            'pid' => $pid,
        ];
    }

    /**
     * PHP CLI yang dipakai untuk melepas proses crawl.
     *
     * PHP_BINARY adalah biner yang menjalankan web server: benar untuk
     * `php -S`, tetapi menunjuk httpd.exe (Apache mod_php) atau php-cgi.exe bila
     * UI dibuka lewat web server tersebut. Urutan pemilihan:
     *   1. CRAWLER_PHP_BINARY pada .env (bila berkasnya ada),
     *   2. PHP_BINARY bila namanya php*.exe/php* (bukan php-cgi),
     *   3. php.exe / php di folder yang sama dengan PHP_BINARY,
     *   4. php.exe / php pada PHP_BINDIR.
     */
    private static function phpBinary(): string
    {
        $override = (string) (Env::get('CRAWLER_PHP_BINARY', '') ?? '');
        if ($override !== '' && self::isPhpBinary($override)) {
            return $override;
        }

        $binary = PHP_BINARY;
        $name = strtolower(pathinfo($binary, PATHINFO_FILENAME));

        if (self::isPhpBinary($binary)) {
            return $binary;
        }

        $bindir = defined('PHP_BINDIR') && PHP_BINDIR !== '' ? PHP_BINDIR : dirname($binary);

        foreach ([
            dirname($binary) . DIRECTORY_SEPARATOR . 'php.exe',
            dirname($binary) . DIRECTORY_SEPARATOR . 'php',
            $bindir . DIRECTORY_SEPARATOR . 'php.exe',
            $bindir . DIRECTORY_SEPARATOR . 'php',
        ] as $candidate) {
            if (self::isPhpBinary($candidate)) {
                return $candidate;
            }
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if (trim($directory) === '') {
                continue;
            }

            foreach (['php.exe', 'php'] as $filename) {
                $candidate = rtrim($directory, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR . $filename;
                if (self::isPhpBinary($candidate)) {
                    return $candidate;
                }
            }
        }

        throw new \RuntimeException(
            'PHP CLI tidak ditemukan. Set CRAWLER_PHP_BINARY ke path php.exe yang benar.'
        );
    }

    private static function isPhpBinary(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $name = strtolower(pathinfo($path, PATHINFO_FILENAME));

        return str_starts_with($name, 'php') && !str_contains($name, 'cgi');
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private static function descriptors(string $consoleLog, string $errorLog): array
    {
        return [
            0 => ['file', self::nullDevice(), 'r'],
            1 => ['file', $consoleLog, 'a'],
            2 => ['file', $errorLog, 'a'],
        ];
    }

    private static function nullDevice(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    }

    /**
     * Susun baris perintah yang bisa dibaca manusia (untuk log/pesan).
     *
     * @param list<string> $parts
     */
    private static function commandLine(array $parts): string
    {
        return implode(' ', array_map([self::class, 'quote'], $parts));
    }

    private static function quote(string $value): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return escapeshellarg($value);
    }
}
