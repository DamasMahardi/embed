<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\Support\Text;

/**
 * Akumulator statistik satu run crawler (dipakai log ringkasan, CLI, dan UI).
 */
final class RunResult
{
    public string $runId = '';

    public string $startedAt = '';

    public string $finishedAt = '';

    public string $status = 'BERJALAN';

    public int $urlsQueued = 0;

    public int $urlsFetched = 0;

    public int $fetchFailed = 0;

    public int $urlsSkipped = 0;

    public int $parseService = 0;

    public int $parseFallback = 0;

    public int $parseFailed = 0;

    /** Halaman yang isinya diambil dari API situs (aturan "content_api"). */
    public int $contentApi = 0;

    /** Halaman kerangka JavaScript yang berhasil dirender browser headless. */
    public int $jsRendered = 0;

    /** Halaman yang gagal dirender browser headless (memakai HTML unduhan). */
    public int $renderFailed = 0;

    /** Halaman yang isinya identik/mirip dengan URL lain pada site yang sama. */
    public int $contentDuplicates = 0;

    /**
     * Halaman yang penemuan dokumen otomatisnya (NetLog -> API halaman) GAGAL.
     * Galat seperti ini tidak menghentikan run: halaman tetap diproses seperti
     * biasa, hanya berkas tambahan dari API halaman itu yang tidak didapat.
     */
    public int $autoDocumentsFailed = 0;

    /** Berapa kali job dijeda (JEDA/LANJUTKAN dari dashboard) pada run ini. */
    public int $pauseCount = 0;

    /** Total lama jeda (detik) pada run ini. */
    public float $pausedSeconds = 0.0;

    public int $documents = 0;

    public int $chunks = 0;

    public int $embedded = 0;

    public int $embedFailed = 0;

    public int $bytes = 0;

    /** Point yang berhasil diunggah otomatis ke Qdrant pada run ini. */
    public int $qdrantPushed = 0;

    /** Point yang gagal diunggah otomatis ke Qdrant pada run ini. */
    public int $qdrantFailed = 0;

    /** @var list<array<string, mixed>> ringkasan push Qdrant per site */
    public array $qdrantRuns = [];

    public float $durationSeconds = 0.0;

    /** @var array<string, array<string, mixed>> */
    public array $sites = [];

    /**
     * Ambil (atau buat) statistik untuk satu site.
     *
     * @return array<string, mixed>
     */
    public function &site(string $id): array
    {
        if (!isset($this->sites[$id])) {
            $this->sites[$id] = [
                'id' => $id,
                'nama' => $id,
                'url_diproses' => 0,
                'url_dilewati' => 0,
                'gagal_unduh' => 0,
                'parse_service' => 0,
                'parse_fallback' => 0,
                'parse_gagal' => 0,
                'konten_api' => 0,
                'render_js' => 0,
                'render_gagal' => 0,
                'dokumen_auto_gagal' => 0,
                'konten_duplikat' => 0,
                'dokumen' => 0,
                'chunk' => 0,
                'vektor' => 0,
                'vektor_gagal' => 0,
                'byte' => 0,
                'durasi' => 0.0,
            ];
        }

        return $this->sites[$id];
    }

    public function addDuration(string $siteId, float $seconds): void
    {
        $stat = &$this->site($siteId);
        $stat['durasi'] = round((float) $stat['durasi'] + $seconds, 2);
    }

    /**
     * Apakah ada push otomatis Qdrant yang tidak tuntas pada run ini
     * (GAGAL / SUKSES_SEBAGIAN / DILEWATI), sehingga pengguna perlu mengulang
     * unggah manual lewat `php bin/crawl.php qdrant --file=...`.
     */
    public function qdrantIncomplete(): bool
    {
        if ($this->qdrantFailed > 0) {
            return true;
        }

        foreach ($this->qdrantRuns as $push) {
            if ((string) ($push['status'] ?? '') !== 'SUKSES') {
                return true;
            }
        }

        return false;
    }

    /**
     * Baris ringkasan yang siap dicetak oleh Logger::summary().
     *
     * @return array<string, mixed>
     */
    public function summaryRows(): array
    {
        $rows = [
            'Run ID' => $this->runId,
            'Status' => $this->status,
            'Mulai' => $this->startedAt,
            'Selesai' => $this->finishedAt,
            // Durasi run ditampilkan dalam menit; detik aslinya tetap ada di
            // dalam tanda kurung supaya mudah dibandingkan dengan log per tahap.
            'Durasi total' => Text::durationMinutes($this->durationSeconds)
                . ' (' . Text::duration($this->durationSeconds) . ')',
            'Site diproses' => count($this->sites),
            'URL masuk antrean' => $this->urlsQueued,
            'URL diunduh OK' => $this->urlsFetched,
            'URL gagal unduh' => $this->fetchFailed,
            'URL dilewati' => $this->urlsSkipped,
            'Parse via /parse' => $this->parseService,
            'Parse fallback lokal' => $this->parseFallback,
            'Parse gagal' => $this->parseFailed,
            'Halaman dirender (JS)' => $this->jsRendered,
            'Render gagal' => $this->renderFailed,
            'Konten via API situs' => $this->contentApi,
            'Konten duplikat dilewati' => $this->contentDuplicates,
            'Penemuan dokumen gagal' => $this->autoDocumentsFailed,
            'Job dijeda (kali)' => $this->pauseCount,
            'Lama jeda' => Text::duration($this->pausedSeconds),
            'Dokumen tersimpan' => $this->documents,
            'Chunk dibentuk' => $this->chunks,
            'Vektor berhasil' => $this->embedded,
            'Vektor gagal' => $this->embedFailed,
            'Total byte HTML' => $this->bytes,
        ];

        // Baris Qdrant hanya muncul bila push otomatis memang dijalankan pada
        // run ini (mis. diaktifkan QDRANT_PUSH_AFTER_CRAWL=true).
        if ($this->qdrantRuns !== []) {
            $rows['Push otomatis Qdrant'] = count($this->qdrantRuns) . ' site';
            $rows['Point ke Qdrant'] = $this->qdrantPushed;
            $rows['Point gagal (Qdrant)'] = $this->qdrantFailed;
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'status' => $this->status,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'duration_seconds' => round($this->durationSeconds, 2),
            'urls_queued' => $this->urlsQueued,
            'urls_fetched' => $this->urlsFetched,
            'fetch_failed' => $this->fetchFailed,
            'urls_skipped' => $this->urlsSkipped,
            'parse_service' => $this->parseService,
            'parse_fallback' => $this->parseFallback,
            'parse_failed' => $this->parseFailed,
            'js_rendered' => $this->jsRendered,
            'render_failed' => $this->renderFailed,
            'content_api' => $this->contentApi,
            'content_duplicates' => $this->contentDuplicates,
            'auto_documents_failed' => $this->autoDocumentsFailed,
            'pause_count' => $this->pauseCount,
            'paused_seconds' => round($this->pausedSeconds, 2),
            'documents' => $this->documents,
            'chunks' => $this->chunks,
            'embedded' => $this->embedded,
            'embed_failed' => $this->embedFailed,
            'qdrant_pushed' => $this->qdrantPushed,
            'qdrant_failed' => $this->qdrantFailed,
            'qdrant_runs' => array_values($this->qdrantRuns),
            'bytes' => $this->bytes,
            'sites' => array_values($this->sites),
        ];
    }
}
