<?php

declare(strict_types=1);

namespace App\Support;

use App\Crawl\SiteConfig;

/**
 * Logger txt untuk hasil crawler.
 *
 * Semua baris ditulis plain text (append, langsung di-flush) sehingga aman
 * dibaca dengan Notepad/more/grep dan tetap ada walau proses berhenti di
 * tengah jalan.
 *
 * Format satu event:
 *   [2026-09-16 09:12:05] [RUN-20260916-091203] [kemendagri] [FETCH_AFTER] status=SUKSES url=https://... http=200 bytes=57551 durasi=1.82s :: Halaman diunduh
 *
 * Setiap tahap punya status SEBELUM dan SESUDAH proses:
 *   FETCH_BEFORE / FETCH_AFTER
 *   PARSE_BEFORE / PARSE_AFTER (status bisa FALLBACK bila parse lokal dipakai)
 *   EMBED_BEFORE / EMBED_AFTER
 *   DOC_AFTER    (hasil akhir per URL: jumlah chunk + vector)
 *
 * Berkas log yang dihasilkan (semua .txt):
 *   logs/crawl-YYYY-MM-DD.txt   master harian, semua site & semua run
 *   logs/runs/<run-id>.txt      detail satu run + ringkasan
 *   logs/sites/<site-id>.txt    riwayat per web yang di-crawl
 *   logs/terbaru.txt            penunjuk log run terakhir + ringkasannya
 */
final class Logger
{
    public const STATUS_PROGRESS = 'PROSES';
    public const STATUS_OK = 'SUKSES';
    public const STATUS_FAIL = 'GAGAL';
    public const STATUS_SKIP = 'DILEWATI';
    public const STATUS_FALLBACK = 'FALLBACK';

    /** @var string[] */
    private array $lines = [];

    private ?string $siteId = null;

    private ?string $url = null;

    private string $dailyFile = '';

    private string $runFile = '';

    private string $siteFile = '';

    private bool $enabled;

    private bool $console;

    /**
     * @param array<string, mixed> $settings konfigurasi logging (config/app.php)
     * @param array<string, mixed> $paths    konfigurasi paths
     */
    public function __construct(
        private array $settings,
        private array $paths,
        private string $runId,
        ?bool $console = null,
    ) {
        $this->enabled = (bool) ($settings['enabled'] ?? true);
        $this->console = $console ?? (bool) ($settings['console'] ?? false);

        if (!$this->enabled) {
            return;
        }

        $logsDir = (string) $paths['logs'];
        $runsDir = (string) $paths['runs'];
        $sitesDir = (string) $paths['sites'];

        ensure_dir($logsDir);
        ensure_dir($runsDir);
        ensure_dir($sitesDir);

        $this->dailyFile = $logsDir . DIRECTORY_SEPARATOR . 'crawl-' . date('Y-m-d') . '.txt';
        $this->runFile = $runsDir . DIRECTORY_SEPARATOR . $this->runId . '.txt';
        $this->siteFile = $sitesDir . DIRECTORY_SEPARATOR . '_umum.txt';

        $banner = $this->banner();

        if ((bool) ($settings['per_run'] ?? true)) {
            $this->writeRaw($this->runFile, $banner);
        }

        if ((bool) ($settings['daily_master'] ?? true)) {
            $this->writeRaw($this->dailyFile, $banner);
        }
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function runFile(): string
    {
        return $this->runFile;
    }

    /**
     * Pindah konteks log ke sebuah site.
     */
    public function useSite(SiteConfig|string $site): void
    {
        $id = $site instanceof SiteConfig ? $site->id : $site;

        if ($this->siteId === $id) {
            return;
        }

        $this->siteId = $id;
        $this->url = null;
        $this->siteFile = rtrim((string) $this->paths['sites'], DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . Text::slug($id, 60) . '.txt';
    }

    public function useUrl(?string $url): void
    {
        $this->url = $url;
    }

    public function clearUrl(): void
    {
        $this->url = null;
    }

    /**
     * Event informatif tanpa status tahapan.
     *
     * @param array<string, mixed> $fields
     */
    public function info(string $stage, string $message, array $fields = []): void
    {
        $this->event($stage, '', $fields, $message);
    }

    /**
     * Status SEBELUM sebuah tahap dijalankan.
     *
     * @param array<string, mixed> $fields
     */
    public function before(string $stage, array $fields = [], ?string $message = null): void
    {
        $this->event($stage, self::STATUS_PROGRESS, $fields, $message);
    }

    /**
     * Status SESUDAH sebuah tahap dijalankan.
     *
     * @param array<string, mixed> $fields
     */
    public function after(string $stage, string $status, array $fields = [], ?string $message = null): void
    {
        $this->event($stage, $status, $fields, $message);
    }

    /**
     * Event umum dengan status bebas (SUKSES/GAGAL/DILEWATI/FALLBACK/PROSES).
     *
     * @param array<string, mixed> $fields
     */
    public function event(string $stage, string $status, array $fields = [], ?string $message = null): void
    {
        if (!$this->enabled) {
            return;
        }

        $line = $this->render($stage, $status, $fields, $message);
        $this->lines[] = $line;

        if ((bool) ($this->settings['daily_master'] ?? true)) {
            $this->writeRaw($this->dailyFile, $line . PHP_EOL);
        }

        if ((bool) ($this->settings['per_run'] ?? true) && $this->runFile !== '') {
            $this->writeRaw($this->runFile, $line . PHP_EOL);
        }

        if ((bool) ($this->settings['per_site'] ?? true) && $this->siteFile !== '') {
            $this->writeRaw($this->siteFile, $line . PHP_EOL);
        }

        if ($this->console) {
            echo $line . PHP_EOL;
        }
    }

    /**
     * Paragraf ringkasan yang mudah dibaca (akhir run / akhir site).
     *
     * @param array<string, mixed> $rows
     */
    public function summary(string $title, array $rows): void
    {
        if (!$this->enabled) {
            return;
        }

        $width = 66;
        $out = [str_repeat('=', $width), 'RINGKASAN: ' . $title];

        foreach ($rows as $label => $value) {
            $out[] = '  ' . str_pad((string) $label, 20, '.') . ' : ' . $this->scalar($value);
        }

        $out[] = str_repeat('=', $width);
        $block = implode(PHP_EOL, $out) . PHP_EOL;

        foreach ($out as $row) {
            $this->lines[] = $row;
        }

        if ((bool) ($this->settings['daily_master'] ?? true)) {
            $this->writeRaw($this->dailyFile, $block);
        }

        if ((bool) ($this->settings['per_run'] ?? true) && $this->runFile !== '') {
            $this->writeRaw($this->runFile, $block);
        }

        if ((bool) ($this->settings['per_site'] ?? true) && $this->siteFile !== '') {
            $this->writeRaw($this->siteFile, $block);
        }

        if ($this->console) {
            echo $block;
        }
    }

    /**
     * Semua baris log yang tercatat di memori (dipakai UI / pengujian).
     *
     * @return string[]
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * Penunjuk run terakhir supaya mudah dibuka dari explorer.
     *
     * @param array<string, mixed> $extra
     */
    public function writeLatestPointer(string $status, array $extra = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $file = rtrim((string) $this->paths['logs'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'terbaru.txt';
        $rows = array_merge([
            'run_id' => $this->runId,
            'status' => $status,
            'log_run' => $this->runFile,
            'log_harian' => $this->dailyFile,
            'dibuat' => date('Y-m-d H:i:s'),
        ], $extra);

        $out = [];
        foreach ($rows as $label => $value) {
            $out[] = str_pad((string) $label, 14) . ': ' . $this->scalar($value);
        }

        $this->writeRaw($file, implode(PHP_EOL, $out) . PHP_EOL, false);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function render(string $stage, string $status, array $fields, ?string $message): string
    {
        $url = $this->url;
        if (isset($fields['url'])) {
            $url = (string) $fields['url'];
            unset($fields['url']);
        }

        $parts = [];
        if ($status !== '') {
            $parts[] = 'status=' . $status;
        }

        if ($url !== null && $url !== '') {
            $parts[] = 'url=' . $url;
        }

        foreach ($fields as $key => $value) {
            $parts[] = $key . '=' . $this->scalar($value);
        }

        $line = sprintf(
            '[%s] [%s] [%s] [%s]',
            date('Y-m-d H:i:s'),
            $this->runId,
            $this->siteId ?? '-',
            $stage
        );

        if ($parts !== []) {
            $line .= ' ' . implode(' ', $parts);
        }

        if ($message !== null && $message !== '') {
            $line .= ' :: ' . Text::oneLine($message);
        }

        return $line;
    }

    private function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '-';
        }

        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $value = $encoded === false ? '[]' : $encoded;
        }

        $text = preg_replace('/\s+/', ' ', (string) $value) ?? (string) $value;

        if ($text === '') {
            return '""';
        }

        if (preg_match('/[\s"=]/', $text) === 1) {
            return '"' . str_replace('"', "'", $text) . '"';
        }

        return $text;
    }

    private function banner(): string
    {
        return str_repeat('=', 66) . PHP_EOL
            . 'RUN ' . $this->runId . ' dimulai ' . date('Y-m-d H:i:s') . PHP_EOL
            . 'Skema: [tanggal jam] [run] [site] [TAHAP] status=.. url=.. detail :: keterangan' . PHP_EOL
            . 'Tahap: FETCH_BEFORE/FETCH_AFTER, PARSE_BEFORE/PARSE_AFTER, '
            . 'EMBED_BEFORE/EMBED_AFTER, DOC_AFTER' . PHP_EOL
            . str_repeat('=', 66) . PHP_EOL;
    }

    private function writeRaw(string $file, string $content, bool $append = true): void
    {
        ensure_dir(dirname($file));

        $flags = $append ? (FILE_APPEND | LOCK_EX) : LOCK_EX;
        @file_put_contents($file, $content, $flags);
    }
}
