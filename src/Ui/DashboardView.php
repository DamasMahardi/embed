<?php

declare(strict_types=1);

namespace App\Ui;

use App\Crawl\SiteConfig;
use App\Support\JobStore;

/** Renderer untuk form dan panel utama dashboard. */
final class DashboardView
{
    /**
     * @param list<SiteConfig> $sites
     * @param bool             $followDocuments nilai bawaan tautan dokumen
     *                                          (CRAWLER_FOLLOW_DOCUMENT_LINKS)
     * @param int              $autoRetry       jatah pengulangan otomatis bila run
     *                                          gagal fatal (CRAWLER_AUTO_RETRY)
     */
    public static function crawlForm(
        array $sites,
        int $maxPages,
        int $maxDepth,
        int $maxRequests,
        int $concurrency,
        bool $followDocuments = false,
        bool $followLinks = true,
        int $autoRetry = 0,
    ): string {
        $siteOptions = '';
        foreach ($sites as $site) {
            $siteOptions .= '<label class="check"><input type="checkbox" name="site[]" value="' . Ui::e($site->id) . '"'
                . ($site->enabled ? ' checked' : '') . '> <span>'
                . Ui::e($site->id) . ($site->enabled ? '' : ' (nonaktif)') . ' &middot; '
                . Ui::e(implode(', ', $site->startUrls)) . '</span></label>';
        }

        if ($sites === []) {
            $siteOptions = '<p class="muted">Belum ada site pada config/sites.json.</p>';
        }

        return '<form method="post" action="index.php">'
            . '<input type="hidden" name="aksi" value="mulai">'
            . '<div class="form-row"><label>Web yang di-crawl</label><div class="checks">' . $siteOptions . '</div>'
            . '<p class="muted" style="margin:6px 0 0">Kosongkan semua centang untuk memakai seluruh site aktif.</p></div>'
            . '<div class="grid-form">'
            . self::numberField('max_pages', 'Batas halaman / site', $maxPages, '0 = TANPA BATAS (bawaan): semua halaman & berkas yang ditemukan diproses; isi > 0 hanya bila ingin membatasi')
            . self::numberField('max_depth', 'Kedalaman tautan', $maxDepth, '0 = hanya start_urls; 1 = ikuti tautan satu tingkat; nilai > 0 mengaktifkan crawl berantai')
            . self::numberField('max_requests', 'Batas permintaan / run', $maxRequests, 'Total unduhan semua site; 0 = bebas')
            . self::numberField('concurrency', 'Konkurensi unduh', $concurrency, 'Jumlah halaman diunduh bersamaan')
            . self::numberField('auto_retry', 'Ulangi otomatis bila gagal', $autoRetry, '0 = tidak diulang; N = job diulang otomatis sampai N kali bila proses berhenti karena galat fatal')
            . '</div>'
            . '<div class="form-row checks">'
            // Bawaan kedua centang mengikuti sakelar global pada config/app.php:
            // crawl.follow_links (CRAWLER_FOLLOW_LINKS) dan
            // crawl.follow_document_links (CRAWLER_FOLLOW_DOCUMENT_LINKS).
            . '<label class="check"><input type="checkbox" name="follow_links" value="1"'
            . ($followLinks ? ' checked' : '') . '> Ikuti tautan (crawl berantai)</label>'
            . '<input type="hidden" name="follow_documents" value="off">'
            . '<label class="check"><input type="checkbox" name="follow_documents" value="on"'
            . ($followDocuments ? ' checked' : '') . '> Ikuti tautan dokumen (PDF/Excel diunduh &amp; diparse)</label>'
            . '<label class="check"><input type="checkbox" name="save_html" value="1" checked> Simpan HTML mentah</label>'
            . '<label class="check"><input type="checkbox" name="robots" value="1" checked> Hormati robots.txt</label>'
            . '</div><button type="submit">Mulai crawl</button></form>';
    }

    public static function addSiteForm(): string
    {
        return '<form method="post" action="index.php">'
            . '<input type="hidden" name="aksi" value="tambah_site">'
            . '<div class="grid-form">'
            . '<div><label for="site_id">ID site (opsional)</label><input id="site_id" name="id" placeholder="contoh-web"></div>'
            . '<div><label for="site_name">Nama web (opsional)</label><input id="site_name" name="name" placeholder="Kosongkan bila tidak perlu"></div>'
            . '<div><label for="start_urls">URL target</label><textarea id="start_urls" name="start_urls" rows="3" placeholder="https://example.com/" required></textarea></div>'
            . '<div><label for="exclude_patterns">Exclude pattern (opsional)</label><textarea id="exclude_patterns" name="exclude_patterns" rows="3" placeholder="/login&#10;\\.(pdf|jpg)$"></textarea></div>'
            . '<div><label for="document_type">Jenis dokumen</label><input id="document_type" name="document_type" placeholder="APBD"></div>'
            . '<div><label for="province">Provinsi</label><input id="province" name="province"></div>'
            . '<div><label for="city">Kota/Kabupaten</label><input id="city" name="city"></div>'
            . '<div><label for="year">Tahun</label><input id="year" name="year" type="number" min="0"></div>'
            . '</div><label class="check"><input type="checkbox" name="enabled" value="1" checked> Aktifkan site</label>'
            . '<button type="submit">Tambah target</button></form>';
    }

    /** @param array<string, mixed> $job */
    public static function jobPanel(array $job, bool $autoRefresh, string $dailyLog, string $consolePath): string
    {
        $jobId = (string) ($job['job_id'] ?? '-');
        $summary = is_array($job['ringkasan'] ?? null) ? $job['ringkasan'] : [];

        $jeda = '';
        if ((string) ($job['dijeda_sejak'] ?? '') !== '') {
            $jeda = Ui::e((string) $job['dijeda_sejak']) . ' (menunggu perintah lanjutkan)';
        } elseif ((string) ($job['jeda_diminta'] ?? '') !== '') {
            $jeda = 'diminta ' . Ui::e((string) $job['jeda_diminta']) . ' (proses berhenti di titik aman berikutnya)';
        } else {
            $jeda = '-';
        }

        if ((float) ($job['total_jeda_detik'] ?? 0) > 0) {
            $jeda .= ' &middot; total jeda ' . Ui::e(Ui::duration((float) $job['total_jeda_detik']));
        }

        $ulangan = '-';
        if ((int) ($job['percobaan_ke'] ?? 1) > 1) {
            $ulangan = 'percobaan ke-' . (int) $job['percobaan_ke'];
        }
        if ((string) ($job['ulangi_dari'] ?? '') !== '') {
            $ulangan = ($ulangan === '-' ? '' : $ulangan . ' &middot; ')
                . 'ulangan dari <a href="index.php?job=' . rawurlencode((string) $job['ulangi_dari']) . '">'
                . Ui::e((string) $job['ulangi_dari']) . '</a>';
        }
        if ((string) ($job['ulangi_ke'] ?? '') !== '') {
            $ulangan = ($ulangan === '-' ? '' : $ulangan . ' &middot; ')
                . 'diulang oleh <a href="index.php?job=' . rawurlencode((string) $job['ulangi_ke']) . '">'
                . Ui::e((string) $job['ulangi_ke']) . '</a>';
        }

        $detail = '<dl class="kv">'
            . '<dt>Job ID</dt><dd>' . Ui::e($jobId) . '</dd>'
            . '<dt>Status</dt><dd>' . Ui::statusBadge((string) ($job['status'] ?? '-'))
            . ($autoRefresh ? ' <span class="muted">(halaman menyegar sendiri setiap 5 detik)</span>' : '') . '</dd>'
            . '<dt>Dibuat</dt><dd>' . Ui::e((string) ($job['dibuat'] ?? '-')) . '</dd>'
            . '<dt>Mulai</dt><dd>' . Ui::e((string) ($job['mulai'] ?? '-')) . '</dd>'
            . '<dt>Selesai</dt><dd>' . Ui::e((string) ($job['selesai'] ?? '-')) . '</dd>'
            . '<dt>Jeda</dt><dd>' . $jeda . '</dd>'
            . '<dt>Pengulangan</dt><dd>' . $ulangan
            . ' &middot; auto-retry sisa ' . (int) ($job['auto_retry'] ?? 0) . '</dd>'
            . '<dt>Web</dt><dd>' . Ui::e(implode(', ', array_map('strval', (array) ($job['sites'] ?? []))) ?: '-') . '</dd>'
            . '<dt>Opsi</dt><dd>' . Ui::e(Ui::value(['o' => $job['options'] ?? []], 'o')) . '</dd>'
            . '<dt>Run ID</dt><dd>' . Ui::e((string) ($job['run_id'] ?? '-')) . '</dd>'
            . '<dt>Pesan</dt><dd>' . Ui::e((string) ($job['pesan'] ?? '-')) . '</dd>'
            . '<dt>PID proses</dt><dd>' . ((int) ($job['pid'] ?? 0) > 0 ? Ui::e((string) $job['pid']) : '-') . '</dd>'
            . '<dt>Perintah</dt><dd class="mono">' . Ui::e((string) ($job['perintah'] ?? '-')) . '</dd>'
            . '<dt>Log run</dt><dd>' . (is_file((string) ($job['log_run'] ?? '')) ? Ui::logLink((string) $job['log_run']) : '-') . '</dd>'
            . '<dt>Log harian</dt><dd>' . Ui::logLink($dailyLog) . '</dd>'
            . '<dt>Log konsol</dt><dd>' . (is_file($consolePath) ? Ui::logLink($consolePath) : Ui::relPath($consolePath)) . '</dd>'
            . '</dl>';

        if ($summary !== []) {
            // Durasi run ditampilkan dalam MENIT (durasi crawl biasanya ratusan
            // detik); nilai detik aslinya tetap dicantumkan sebagai rincian.
            $durasiDetik = $summary['duration_seconds'] ?? null;
            $durasi = is_numeric($durasiDetik)
                ? Ui::e(Ui::durationMinutes((float) $durasiDetik))
                    . ' <span class="muted">(' . Ui::e(Ui::duration((float) $durasiDetik)) . ')</span>'
                : Ui::e(Ui::value($summary, 'duration_seconds'));

            $detail .= '<h2 style="margin-top:18px">Ringkasan run</h2><dl class="kv">'
                . '<dt>Status run</dt><dd>' . Ui::runBadge((string) ($summary['status'] ?? '')) . '</dd>'
                . '<dt>Durasi</dt><dd>' . $durasi . '</dd>'
                . '<dt>URL diunduh</dt><dd>' . Ui::e(Ui::value($summary, 'urls_fetched')) . ' dari ' . Ui::e(Ui::value($summary, 'urls_queued')) . ' antrean</dd>'
                . '<dt>Parse via /parse</dt><dd>' . Ui::e(Ui::value($summary, 'parse_service')) . '</dd>'
                . '<dt>Parse lokal (fallback)</dt><dd>' . Ui::e(Ui::value($summary, 'parse_fallback')) . '</dd>'
                . '<dt>Dokumen / chunk / vektor</dt><dd>' . Ui::e(Ui::value($summary, 'documents')) . ' dokumen &middot; '
                . Ui::e(Ui::value($summary, 'chunks')) . ' chunk &middot; ' . Ui::e(Ui::value($summary, 'embedded')) . ' vektor</dd>'
                . '<dt>Total byte HTML</dt><dd>' . Ui::bytes((int) ($summary['bytes'] ?? 0)) . '</dd></dl>';
        }

        return '<div class="panel"><h2>Job ' . Ui::e($jobId) . '</h2>' . $detail . self::jobActions($job) . '</div>';
    }

    /** @param list<SiteConfig> $sites */
    public static function siteTable(array $sites, string $description): string
    {
        $rows = '';
        foreach ($sites as $site) {
            $rows .= '<tr><td><strong>' . Ui::e($site->id) . '</strong></td>'
                . '<td>' . ($site->enabled ? Ui::runBadge('AKTIF') : Ui::runBadge('NONAKTIF')) . '</td>'
                . '<td>' . Ui::e($site->documentMode ? 'dokumen' : 'web') . ' &middot; ' . Ui::e($site->documentType()) . '</td>'
                . '<td>' . Ui::e(implode(', ', $site->startUrls)) . '</td><td>'
                . ($site->maxPages === 0 ? 'tanpa batas' : (string) $site->maxPages) . '</td>'
                . '<td>' . $site->maxDepth . '</td><td>' . $site->rateLimitMs . ' ms</td>'
                . '<td>' . Ui::e($site->followLinks ? 'ya' : 'tidak') . '</td></tr>';
        }

        return '<div class="panel" id="sites"><h2>Web pada config/sites.json (' . count($sites) . ')</h2>'
            . '<table><thead><tr><th>ID</th><th>Status</th><th>Jenis</th><th>URL mulai</th><th>Halaman</th><th>Kedalaman</th><th>Jeda</th><th>Ikuti tautan</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table><p class="muted" style="margin-top:10px">' . $description . '</p></div>';
    }

    /** @param list<array<string, mixed>> $jobs */
    public static function jobTable(array $jobs): string
    {
        $rows = '';
        foreach ($jobs as $item) {
            $id = (string) ($item['job_id'] ?? '-');
            $summary = is_array($item['ringkasan'] ?? null) ? $item['ringkasan'] : [];
            $rows .= '<tr><td>' . Ui::e($id) . '</td><td>' . Ui::statusBadge((string) ($item['status'] ?? '-')) . '</td>'
                . '<td>' . Ui::e((string) ($item['mulai'] ?? '-')) . '</td><td>' . Ui::e(implode(', ', array_map('strval', (array) ($item['sites'] ?? []))) ?: '-') . '</td>'
                . '<td>' . Ui::e(Ui::value($summary, 'documents')) . '</td><td>' . Ui::e(Ui::value($summary, 'chunks')) . '</td><td>' . Ui::e(Ui::value($summary, 'embedded')) . '</td>'
                . '<td><a href="index.php?job=' . rawurlencode($id) . '">detail</a>'
                . self::jobActions($item) . '</td></tr>';
        }

        return '<div class="panel"><h2>Job terakhir</h2><table><thead><tr><th>Job ID</th><th>Status</th><th>Mulai</th><th>Web</th><th>Dokumen</th><th>Chunk</th><th>Vektor</th><th>Aksi</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table><p class="muted" style="margin-top:10px">Riwayat lengkap ada di '
            . '<a href="logs.php#job">halaman Log &amp; Job</a> &mdash; di sana tersedia tombol '
            . '<strong>Hapus semua</strong> untuk riwayat job, log, dan berkas vektor.'
            . ' Job yang berjalan bisa <strong>dijeda</strong> lalu <strong>dilanjutkan</strong> tanpa membatalkan prosesnya, '
            . 'dan job yang gagal bisa <strong>diulang</strong> dengan site + opsi yang sama.</p></div>';
    }

    /**
     * Tombol aksi sebuah job: Jeda/Lanjutkan (job yang masih berjalan) dan
     * Ulangi job (job yang sudah berhenti), semuanya form POST ke dashboard.
     *
     * Dipakai panel detail job pada dashboard maupun kolom aksi tabel riwayat,
     * supaya tombol di kedua tempat selalu sama.
     *
     * @param array<string, mixed> $job
     */
    public static function jobActions(array $job, string $action = 'index.php'): string
    {
        $jobId = (string) ($job['job_id'] ?? '');

        if ($jobId === '') {
            return '';
        }

        $status = strtoupper((string) ($job['status'] ?? ''));
        $tombol = '';

        $form = static function (string $aksi, string $label, string $kelas, string $konfirmasi = '') use ($jobId, $action): string {
            return '<form method="post" action="' . Ui::e($action) . '" class="inline-form"'
                . ($konfirmasi === '' ? '' : ' onsubmit="return confirm(\'' . Ui::e($konfirmasi) . '\')"') . '>'
                . '<input type="hidden" name="aksi" value="' . Ui::e($aksi) . '">'
                . '<input type="hidden" name="job_id" value="' . Ui::e($jobId) . '">'
                . '<button type="submit"' . ($kelas === '' ? '' : ' class="' . Ui::e($kelas) . '"') . '>'
                . Ui::e($label) . '</button></form>';
        };

        if (in_array($status, [JobStore::STATUS_WAITING, JobStore::STATUS_RUNNING, JobStore::STATUS_PAUSED], true)) {
            $tombol .= $status === JobStore::STATUS_PAUSED
                ? $form('lanjut', 'Lanjutkan', '', '')
                : $form('jeda', 'Jeda', 'secondary', 'Jeda job ini? Proses berhenti di titik aman berikutnya.');
        } else {
            $tombol .= $form('ulangi', 'Ulangi job', '', 'Jalankan ulang job ini dengan site & opsi yang sama?');
        }

        return '<div class="actions">' . $tombol . '</div>';
    }

    private static function numberField(string $name, string $label, int $value, string $hint): string
    {
        return '<div><label for="' . Ui::e($name) . '">' . Ui::e($label) . '</label>'
            . '<input type="number" id="' . Ui::e($name) . '" name="' . Ui::e($name) . '" min="0" value="' . $value . '">'
            . '<p class="muted" style="margin:4px 0 0">' . Ui::e($hint) . '</p></div>';
    }
}
