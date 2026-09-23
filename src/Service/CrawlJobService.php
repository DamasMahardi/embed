<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\JobLauncher;
use App\Support\JobStore;

/**
 * Use case untuk membuat dan melepas satu crawl ke proses CLI latar belakang.
 *
 * Selain memulai job baru (start()), layanan ini bisa MENGULANG job yang gagal
 * (retry()): site + opsi disalin dari job lama, lalu dijalankan sebagai job
 * BARU sehingga riwayat lama tetap utuh dan bisa dibandingkan di dashboard.
 */
final class CrawlJobService
{
    public function __construct(
        private JobStore $jobs,
        private string $basePath,
    ) {
    }

    /**
     * @param list<string> $siteIds
     * @param array<string, mixed> $options
     * @return array{ok: bool, job_id: string, pesan: string, pid: int, command: string}
     */
    public function start(array $siteIds, array $options): array
    {
        return $this->launch($siteIds, $options);
    }

    /**
     * Ulangi job dengan site + opsi yang sama.
     *
     * Dipakai tombol "Ulangi job" pada dashboard dan pengulangan otomatis
     * setelah galat fatal (lihat Application::crawl()). Job yang masih
     * berjalan/dijeda tidak diulang supaya tidak ada dua proses pada antrean
     * dan kuota yang sama.
     *
     * @param int|null $sisaAutoRetry jatah pengulangan otomatis untuk job baru
     *                                (null = pakai nilai pada opsi job lama)
     *
     * @return array{ok: bool, job_id: string, pesan: string, pid: int, command: string}
     */
    public function retry(string $jobId, ?int $sisaAutoRetry = null): array
    {
        $job = $this->jobs->get($jobId);

        if ($job === null) {
            return $this->failed($jobId, 'Job tidak ditemukan: ' . $jobId);
        }

        if (JobStore::isActiveStatus((string) ($job['status'] ?? ''))) {
            return $this->failed(
                $jobId,
                'Job ' . $jobId . ' masih berjalan/dijeda; jeda atau tunggu selesai sebelum mengulangnya.'
            );
        }

        $sites = array_values(array_filter(
            array_map('strval', (array) ($job['sites'] ?? [])),
            static fn (string $id): bool => $id !== ''
        ));

        $options = is_array($job['options'] ?? null) ? $job['options'] : [];
        $autoRetry = $sisaAutoRetry ?? max(0, (int) ($options['auto_retry'] ?? $job['auto_retry'] ?? 0));
        $options['auto_retry'] = $autoRetry;

        return $this->launch($sites, $options, [
            'ulangi_dari' => $jobId,
            'percobaan_ke' => max(1, (int) ($job['percobaan_ke'] ?? 1)) + 1,
            'auto_retry' => $autoRetry,
        ], $jobId);
    }

    /**
     * @param list<string>         $siteIds
     * @param array<string, mixed> $options
     * @param array<string, mixed> $extra       kolom tambahan berkas job
     * @param string|null          $ulangiDari  job lama yang diulang (bila ada)
     *
     * @return array{ok: bool, job_id: string, pesan: string, pid: int, command: string}
     */
    private function launch(array $siteIds, array $options, array $extra = [], ?string $ulangiDari = null): array
    {
        $job = $this->jobs->create($siteIds, $options, null, $extra);
        $jobId = (string) $job['job_id'];
        $launch = JobLauncher::launch(
            $this->basePath,
            $this->arguments($jobId, $siteIds, $options),
            $this->jobs->consolePath($jobId),
            $this->jobs->errorPath($jobId),
        );

        if (!$launch['ok']) {
            $this->jobs->markFailed($jobId, (string) $launch['pesan']);

            return [
                'ok' => false,
                'job_id' => $jobId,
                'pesan' => (string) $launch['pesan'],
                'pid' => 0,
                'command' => (string) $launch['command'],
            ];
        }

        $this->jobs->update($jobId, [
            'pid' => $launch['pid'],
            'perintah' => $launch['command'],
        ]);

        // Job lama ditandai supaya tautan "diulang oleh" terlihat di dashboard.
        if ($ulangiDari !== null && $ulangiDari !== '') {
            $this->jobs->update($ulangiDari, ['ulangi_ke' => $jobId]);
        }

        return [
            'ok' => true,
            'job_id' => $jobId,
            'pesan' => (string) $launch['pesan'],
            'pid' => (int) $launch['pid'],
            'command' => (string) $launch['command'],
        ];
    }

    /**
     * Argumen CLI `crawl` dari opsi job (dipakai start() dan retry()).
     *
     * @param list<string>         $siteIds
     * @param array<string, mixed> $options
     *
     * @return list<string>
     */
    private function arguments(string $jobId, array $siteIds, array $options): array
    {
        $arguments = ['crawl', '--job=' . $jobId];

        if ($siteIds !== []) {
            $arguments[] = '--site=' . implode(',', $siteIds);
        }

        if ((int) ($options['max_pages'] ?? 0) > 0) {
            $arguments[] = '--max-pages=' . (int) $options['max_pages'];
        }

        $arguments[] = '--max-depth=' . (int) ($options['max_depth'] ?? 0);

        if ((int) ($options['max_requests'] ?? 0) > 0) {
            $arguments[] = '--max-requests=' . (int) $options['max_requests'];
        }

        $arguments[] = '--concurrency=' . max(1, (int) ($options['concurrency'] ?? 1));
        $arguments[] = !empty($options['follow_links']) ? '--follow' : '--no-follow';
        $arguments[] = !empty($options['save_html']) ? '--save-html' : '--no-save-html';
        $arguments[] = !empty($options['respect_robots']) ? '--robots' : '--no-robots';

        // Tautan berkas dokumen (PDF/Excel) pada halaman web: hanya dikirim bila
        // form UI memilihnya, supaya nilai bawaan .env
        // (CRAWLER_FOLLOW_DOCUMENT_LINKS) tetap dipakai bila tidak dipilih.
        $followDocuments = (string) ($options['follow_documents'] ?? '');
        if ($followDocuments === 'on') {
            $arguments[] = '--follow-documents';
        } elseif ($followDocuments === 'off') {
            $arguments[] = '--no-follow-documents';
        }

        // Jatah pengulangan otomatis ikut dikirim sebagai argumen supaya job
        // hasil pengulangan tidak kehilangan jatahnya bila gagal lagi.
        $arguments[] = '--auto-retry=' . max(0, (int) ($options['auto_retry'] ?? 0));

        return $arguments;
    }

    /**
     * @return array{ok: bool, job_id: string, pesan: string, pid: int, command: string}
     */
    private function failed(string $jobId, string $message): array
    {
        return ['ok' => false, 'job_id' => $jobId, 'pesan' => $message, 'pid' => 0, 'command' => ''];
    }
}
