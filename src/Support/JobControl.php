<?php

declare(strict_types=1);

namespace App\Support;

use Closure;

/**
 * Pengendali job dari LUAR proses (dashboard/CLI): jeda dan lanjutkan.
 *
 * Crawler berjalan sebagai proses CLI tanpa daemon, jadi perintah dari UI
 * disampaikan lewat berkas bendera: UI membuat storage/jobs/<job_id>.pause,
 * proses CLI memeriksa berkas itu di TITIK AMAN (antar batch halaman dan antar
 * site), lalu menunggu sampai berkasnya dihapus:
 *
 *   [JOB_PAUSE] -> tunggu (polling) -> [JOB_RESUME]
 *
 * Pemeriksaan hanya dilakukan di titik aman supaya tidak ada unduhan yang
 * terpotong di tengah: batch halaman yang sedang berjalan (maksimal
 * CRAWL_CONCURRENCY halaman + /parse + /embed-nya) diselesaikan lebih dahulu.
 *
 * Tanpa berkas bendera (mis. `php bin/crawl.php crawl --site=...` langsung,
 * tanpa --job) pengendali ini tidak aktif sehingga perilakunya sama seperti
 * sebelum fitur jeda ada.
 */
final class JobControl
{
    private ?Closure $onChange;

    /**
     * @param string|null                            $pauseFlag path berkas bendera jeda
     * @param (callable(bool, float): void)|null     $onChange  dipanggil saat masuk/keluar jeda
     * @param int                                    $pollMs    jeda pemeriksaan berkas (milidetik)
     */
    public function __construct(
        private ?string $pauseFlag = null,
        ?callable $onChange = null,
        private int $pollMs = 1000,
    ) {
        $this->onChange = $onChange === null ? null : Closure::fromCallable($onChange);
    }

    /**
     * Pengendali yang tidak pernah menjeda (dipakai run tanpa job).
     */
    public static function inactive(): self
    {
        return new self(null);
    }

    public function enabled(): bool
    {
        return $this->pauseFlag !== null && $this->pauseFlag !== '';
    }

    public function flagPath(): ?string
    {
        return $this->pauseFlag;
    }

    /**
     * Apakah job diminta dijeda? Dibaca langsung dari berkas supaya perintah
     * dashboard berlaku pada pemeriksaan berikutnya (tanpa daemon/heartbeat).
     */
    public function paused(): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        $flag = (string) $this->pauseFlag;
        clearstatcache(true, $flag);

        return is_file($flag);
    }

    /**
     * Tunggu selama job masih diminta dijeda; kembali setelah berkas bendera
     * dihapus (dilanjutkan). Mengembalikan lama jeda dalam detik.
     *
     * Job yang dijeda TIDAK dibatalkan: proses tetap hidup dan melanjutkan
     * antreannya sendiri dari titik yang sama setelah dilanjutkan.
     */
    public function waitUntilResumed(): float
    {
        if (!$this->paused()) {
            return 0.0;
        }

        $mulai = microtime(true);
        $this->notify(true, 0.0);

        while ($this->paused()) {
            usleep(max(100, $this->pollMs) * 1000);
        }

        $detik = microtime(true) - $mulai;
        $this->notify(false, $detik);

        return $detik;
    }

    private function notify(bool $paused, float $seconds): void
    {
        if ($this->onChange === null) {
            return;
        }

        ($this->onChange)($paused, $seconds);
    }
}
