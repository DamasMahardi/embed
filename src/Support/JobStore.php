<?php

declare(strict_types=1);

namespace App\Support;

use App\Pipeline\RunResult;

/**
 * Antrean job crawl sederhana berbasis berkas JSON.
 *
 * UI (public/index.php) menulis satu berkas job ke storage/jobs/<job_id>.json
 * lalu menjalankan `php bin/crawl.php crawl --job=<id>` di latar belakang.
 * Proses CLI itu sendiri yang memperbarui status job (MENUNGGU -> BERJALAN ->
 * SELESAI/GAGAL), sehingga UI cukup membaca berkas tanpa perlu daemon/heartbeat.
 *
 * Dua pengendali dari UI memakai berkas pendamping:
 *   <job_id>.pause  permintaan JEDA (dibuat UI, dibaca proses CLI; proses
 *                   menulis status DIJEDA lalu menunggu berkasnya dihapus),
 *   <job_id>.lock   kunci tulis berkas job antar proses (flock).
 *
 * Job yang gagal bisa diulang: `CrawlJobService::retry()` membuat job BARU
 * dengan site + opsi yang sama, lalu kedua berkas job ditautkan lewat kolom
 * "ulangi_dari" (job lama) dan "ulangi_ke" (job baru).
 */
final class JobStore
{
    public const STATUS_WAITING = 'MENUNGGU';
    public const STATUS_RUNNING = 'BERJALAN';
    public const STATUS_PAUSED = 'DIJEDA';
    public const STATUS_DONE = 'SELESAI';
    public const STATUS_FAILED = 'GAGAL';

    /**
     * Status job yang prosesnya (mungkin) masih hidup, sehingga tombol
     * Jeda/Lanjutkan relevan dan halaman UI perlu menyegar sendiri.
     */
    public static function isActiveStatus(string $status): bool
    {
        return in_array(strtoupper($status), [self::STATUS_WAITING, self::STATUS_RUNNING, self::STATUS_PAUSED], true);
    }

    private string $dir;

    /** @param array<string, mixed> $paths bagian config "paths" */
    public function __construct(private array $paths)
    {
        $this->dir = rtrim((string) ($paths['jobs'] ?? base_path('storage/jobs')), "/\\");
    }

    public function dir(): string
    {
        return ensure_dir($this->dir);
    }

    public function path(string $jobId): string
    {
        return $this->dir() . DIRECTORY_SEPARATOR . $this->safeId($jobId) . '.json';
    }

    /**
     * Buat job baru berstatus MENUNGGU.
     *
     * @param list<string>         $sites
     * @param array<string, mixed> $options
     * @param array<string, mixed> $extra   kolom tambahan (mis. ulangi_dari,
     *                                      percobaan_ke, auto_retry)
     *
     * @return array<string, mixed>
     */
    public function create(array $sites, array $options, ?string $jobId = null, array $extra = []): array
    {
        $jobId ??= self::newId();

        $job = [
            'job_id' => $jobId,
            'status' => self::STATUS_WAITING,
            'sites' => array_values($sites),
            'options' => $options,
            'dibuat' => date('Y-m-d H:i:s'),
            'mulai' => null,
            'selesai' => null,
            'run_id' => null,
            'log_run' => null,
            'log_console' => $this->consolePath($jobId),
            'pesan' => null,
            'ringkasan' => null,
            // Percobaan ke-berapa (1 = job asli) + tautan ke job yang diulang.
            'percobaan_ke' => 1,
            'ulangi_dari' => null,
            'ulangi_ke' => null,
            // Jatah pengulangan OTOMATIS setelah galat fatal (0 = tidak diulang).
            'auto_retry' => 0,
            // Info jeda (diisi proses crawl saat benar-benar berhenti sementara).
            'jeda_diminta' => null,
            'dijeda_sejak' => null,
            'total_jeda_detik' => 0.0,
        ];

        $job = array_merge($job, $extra);

        $this->write($job);

        return $job;
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>|null
     */
    public function update(string $jobId, array $changes): ?array
    {
        return $this->withLock($jobId, function () use ($jobId, $changes): ?array {
            // Dibaca ulang di dalam kunci supaya perubahan yang baru saja
            // ditulis proses lain (mis. UI menyimpan pid) tidak ikut tertimpa.
            $job = $this->get($jobId);
            if ($job === null) {
                return null;
            }

            $job = array_merge($job, $changes);
            $this->write($job);

            return $job;
        });
    }

    public function markRunning(string $jobId, string $runId, string $runLogFile): ?array
    {
        // Berkas bendera jeda SENGAJA tidak dihapus di sini: bila pengguna sudah
        // menekan "Jeda" saat job masih MENUNGGU (proses belum jalan), permintaan
        // itu harus tetap berlaku sehingga proses berhenti di titik aman pertama.
        return $this->update($jobId, [
            'status' => self::STATUS_RUNNING,
            'mulai' => date('Y-m-d H:i:s'),
            'run_id' => $runId,
            'log_run' => $runLogFile,
            'pesan' => is_file($this->pauseFlagPath($jobId))
                ? 'Crawl mulai berjalan; permintaan jeda sudah aktif dan akan dipatuhi di titik aman pertama.'
                : 'Crawl sedang berjalan.',
            'dijeda_sejak' => null,
        ]);
    }

    public function markFinished(string $jobId, RunResult $result, string $runLogFile): ?array
    {
        $ok = in_array($result->status, ['SUKSES', 'SUKSES_SEBAGIAN'], true);

        $this->clearPauseFlag($jobId);

        return $this->update($jobId, [
            'status' => $ok ? self::STATUS_DONE : self::STATUS_FAILED,
            'selesai' => date('Y-m-d H:i:s'),
            'run_id' => $result->runId,
            'log_run' => $runLogFile,
            'pesan' => 'Run selesai dengan status ' . $result->status . '.',
            'ringkasan' => $result->toArray(),
            'jeda_diminta' => null,
            'dijeda_sejak' => null,
            // total_jeda_detik TIDAK ditambah di sini: nilainya sudah diakumulasi
            // oleh markResumed() setiap kali proses crawl dilanjutkan (kalau
            // ditambah lagi di sini, lama jeda terhitung dua kali).
        ]);
    }

    public function markFailed(string $jobId, string $message): ?array
    {
        $this->clearPauseFlag($jobId);

        return $this->update($jobId, [
            'status' => self::STATUS_FAILED,
            'selesai' => date('Y-m-d H:i:s'),
            'pesan' => Text::oneLine($message, 300),
            'jeda_diminta' => null,
            'dijeda_sejak' => null,
        ]);
    }

    // -----------------------------------------------------------------------
    // Jeda / lanjutkan job (dikendalikan dari dashboard, lihat JobControl).
    // -----------------------------------------------------------------------

    /**
     * Berkas bendera permintaan jeda: dibuat UI/CLI, dibaca proses crawl.
     */
    public function pauseFlagPath(string $jobId): string
    {
        return $this->dir() . DIRECTORY_SEPARATOR . $this->safeId($jobId) . '.pause';
    }

    public function pauseRequested(string $jobId): bool
    {
        $flag = $this->pauseFlagPath($jobId);
        clearstatcache(true, $flag);

        return is_file($flag);
    }

    /**
     * Minta proses crawl berhenti sementara di titik aman berikutnya.
     *
     * Status TIDAK langsung diubah: hanya proses crawl yang tahu kapan titik
     * amannya, sehingga status DIJEDA ditulis oleh proses tersebut
     * (markPaused()). Job yang sudah selesai/gagal tidak dijeda.
     *
     * @return array<string, mixed>|null
     */
    public function requestPause(string $jobId): ?array
    {
        $job = $this->get($jobId);

        if ($job === null || !self::isActiveStatus((string) ($job['status'] ?? ''))) {
            return $job;
        }

        $flag = $this->pauseFlagPath($jobId);
        ensure_dir(dirname($flag));
        @file_put_contents($flag, 'jeda ' . date('Y-m-d H:i:s') . PHP_EOL, LOCK_EX);

        return $this->update($jobId, [
            'jeda_diminta' => date('Y-m-d H:i:s'),
            'pesan' => $job['status'] === self::STATUS_PAUSED
                ? 'Job sudah dijeda; permintaan jeda diperbarui.'
                : 'Permintaan jeda dikirim; proses berhenti di titik aman berikutnya (antar batch halaman).',
        ]);
    }

    /**
     * Lanjutkan job yang dijeda (hapus berkas bendera). Proses crawl sendiri
     * yang mengubah status kembali ke BERJALAN.
     *
     * @return array<string, mixed>|null
     */
    public function clearPause(string $jobId): ?array
    {
        $job = $this->get($jobId);
        $this->clearPauseFlag($jobId);

        if ($job === null) {
            return null;
        }

        return $this->update($jobId, [
            'jeda_diminta' => null,
            'pesan' => $job['status'] === self::STATUS_PAUSED
                ? 'Perintah lanjutkan dikirim; proses meneruskan antrean.'
                : (string) ($job['pesan'] ?? ''),
        ]);
    }

    /**
     * Ditulis proses crawl saat benar-benar berhenti sementara.
     *
     * @return array<string, mixed>|null
     */
    public function markPaused(string $jobId, string $since): ?array
    {
        return $this->update($jobId, [
            'status' => self::STATUS_PAUSED,
            'dijeda_sejak' => $since,
            'pesan' => 'Job dijeda pada ' . $since . '; proses menunggu perintah lanjutkan.',
        ]);
    }

    /**
     * Ditulis proses crawl setelah dilanjutkan.
     *
     * @return array<string, mixed>|null
     */
    public function markResumed(string $jobId, float $pausedSeconds): ?array
    {
        $job = $this->get($jobId);
        $total = round((float) ($job['total_jeda_detik'] ?? 0) + max(0.0, $pausedSeconds), 2);

        return $this->update($jobId, [
            'status' => self::STATUS_RUNNING,
            'dijeda_sejak' => null,
            'jeda_diminta' => null,
            'total_jeda_detik' => $total,
            'pesan' => 'Job dilanjutkan setelah dijeda ' . Text::duration(max(0.0, $pausedSeconds)) . '.',
        ]);
    }

    private function clearPauseFlag(string $jobId): void
    {
        $flag = $this->pauseFlagPath($jobId);

        if (is_file($flag)) {
            @unlink($flag);
        }
    }

    /**
     * Tandai job yang proses anaknya sudah berhenti tanpa sempat memperbarui
     * status. Job yang masih hidup tidak disentuh.
     *
     * @return array<string, mixed>|null
     */
    public function reconcile(string $jobId): ?array
    {
        $job = $this->get($jobId);
        if ($job === null || !self::isActiveStatus((string) ($job['status'] ?? ''))) {
            return $job;
        }

        $createdAt = strtotime((string) ($job['dibuat'] ?? '')) ?: time();
        if (time() - $createdAt < 5) {
            return $job;
        }

        $pid = (int) ($job['pid'] ?? 0);
        if ($pid > 0 && self::processAlive($pid)) {
            return $job;
        }

        $errorFile = $this->errorPath($jobId);
        $error = is_file($errorFile) ? trim((string) file_get_contents($errorFile)) : '';
        $message = 'Proses crawl berhenti sebelum status job diperbarui.';
        if ($error !== '') {
            $message .= ' Error: ' . Text::oneLine($error, 220);
        }

        return $this->markFailed($jobId, $message);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $jobId): ?array
    {
        $file = $this->path($jobId);
        if (!is_file($file)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Job terbaru berdasarkan waktu file.
     *
     * @return array<string, mixed>|null
     */
    public function latest(): ?array
    {
        $all = $this->all(1);

        return $all[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(int $limit = 50): array
    {
        $files = glob($this->dir() . DIRECTORY_SEPARATOR . '*.json') ?: [];

        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        $jobs = [];
        foreach (array_slice($files, 0, max(1, $limit)) as $file) {
            if (str_ends_with($file, '.console.json')) {
                continue;
            }

            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded)) {
                $jobs[] = $decoded;
            }
        }

        return $jobs;
    }

    public function consolePath(string $jobId): string
    {
        return $this->dir() . DIRECTORY_SEPARATOR . $this->safeId($jobId) . '.console.txt';
    }

    public function errorPath(string $jobId): string
    {
        return $this->dir() . DIRECTORY_SEPARATOR . $this->safeId($jobId) . '.error.txt';
    }

    private static function processAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $process = @proc_open(
                ['tasklist.exe', '/FI', 'PID eq ' . $pid, '/FO', 'CSV', '/NH'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                null,
                ['bypass_shell' => true],
            );

            if (!is_resource($process)) {
                return false;
            }

            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            return preg_match('/"[^"]+","' . preg_quote((string) $pid, '/') . '",/i', (string) $output) === 1;
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return is_dir('/proc/' . $pid);
    }

    public static function newId(): string
    {
        return 'JOB-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
    }

    /**
     * Jalankan operasi baca-ubah-tulis di bawah kunci eksklusif antar proses,
     * supaya UI dan proses CLI tidak saling menimpa berkas job yang sama.
     */
    private function withLock(string $jobId, callable $operation): mixed
    {
        $lockFile = $this->dir() . DIRECTORY_SEPARATOR . $this->safeId($jobId) . '.lock';
        $handle = @fopen($lockFile, 'c');

        if ($handle === false) {
            return $operation();
        }

        try {
            @flock($handle, LOCK_EX);

            return $operation();
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * @param array<string, mixed> $job
     */
    private function write(array $job): void
    {
        ensure_dir($this->dir());
        @file_put_contents(
            $this->path((string) $job['job_id']),
            json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    private function safeId(string $jobId): string
    {
        return Text::slug($jobId, 60) === '' ? 'job' : Text::slug($jobId, 60);
    }
}
