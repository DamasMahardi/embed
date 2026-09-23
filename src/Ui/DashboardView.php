<?php

declare(strict_types=1);

namespace App\Ui;

use App\Crawl\SiteConfig;
use App\Support\JobStore;
use App\Support\Text;

/** Renderer untuk form dan panel utama dashboard. */
final class DashboardView
{
    /**
     * @param list<SiteConfig> $sites
     * @param bool             $followDocuments nilai bawaan tautan dokumen
     *                                          (CRAWLER_FOLLOW_DOCUMENT_LINKS)
     * @param int              $autoRetry       jatah pengulangan otomatis bila run
     *                                          gagal fatal (CRAWLER_AUTO_RETRY)
     * @param string           $followExternal  mode bawaan jelajah situs lain
     *                                          (off|family|all)
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
        string $followExternal = 'family',
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

        $externalOptions = '';
        foreach ([
            'off' => 'off - hanya host pada URL target',
            'family' => 'family - satu keluarga domain (mis. *.kemendagri.go.id)',
            'all' => 'all - semua host yang ditautkan (dibatasi jumlah host)',
        ] as $value => $label) {
            $externalOptions .= '<option value="' . $value . '"'
                . ($followExternal === $value ? ' selected' : '') . '>' . Ui::e($label) . '</option>';
        }

        return '<form method="post" action="index.php">'
            . '<input type="hidden" name="aksi" value="mulai">'
            . '<div class="form-row"><label>Web yang di-crawl</label><div class="checks">' . $siteOptions . '</div>'
            . '<p class="muted" style="margin:6px 0 0">Kosongkan semua centang untuk memakai seluruh site aktif.</p></div>'
            . '<div class="grid-form">'
            . self::numberField('max_pages', 'Batas halaman / site', $maxPages, '0 = TANPA BATAS (bawaan): semua halaman & berkas yang ditemukan diproses; isi > 0 hanya bila ingin membatasi')
            . self::numberField('max_depth', 'Kedalaman tautan', $maxDepth, '-1 = TANPA BATAS (bawaan): seluruh situs dijelajahi sampai tidak ada tautan baru; 0 = hanya start_urls; 1 = ikuti tautan satu tingkat')
            . self::numberField('max_requests', 'Batas permintaan / run', $maxRequests, 'Total unduhan semua site; 0 = bebas')
            . self::numberField('concurrency', 'Konkurensi unduh', $concurrency, 'Jumlah halaman diunduh bersamaan')
            . self::numberField('auto_retry', 'Ulangi otomatis bila gagal', $autoRetry, '0 = tidak diulang; N = job diulang otomatis sampai N kali bila proses berhenti karena galat fatal')
            . '<div><label for="follow_external">Jelajahi situs lain</label><select id="follow_external" name="follow_external">'
            . $externalOptions
            . '</select><p class="muted" style="margin:6px 0 0">Situs .go.id sering memecah isi ke subdomain lain '
            . '(otda.kemendagri.go.id, polpum.kemendagri.go.id, ...); mode <em>family</em> mengikutinya tanpa keluar dari keluarga domain.</p></div>'
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

    /**
     * Form "Cari website berdasarkan domain": input domain + metadata dokumen.
     *
     * Hasil pencarian (subdomain yang aktif) ditulis ke config/sites.json dan
     * langsung tampil di daftar web pada dashboard sebelum di-crawl.
     */
    public static function discoverForm(): string
    {
        return '<form method="post" action="index.php">'
            . '<input type="hidden" name="aksi" value="discover">'
            . '<div class="grid-form">'
            . '<div><label for="domain">Domain</label><input id="domain" name="domain" placeholder="kemendagri.go.id" required>'
            . '<p class="muted" style="margin:6px 0 0">Ketik nama domain saja (contoh: kemendagri.go.id). '
            . 'Crawler akan mencari subdomain aktif seperti ppid.kemendagri.go.id, otda.kemendagri.go.id, dst. '
            . 'Hasil + log disimpan ke DATABASE (panel Riwayat crawl).</p></div>'
            . '<div><label for="document_type">Jenis dokumen</label><input id="document_type" name="document_type" placeholder="APBD"></div>'
            . '<div><label for="province">Provinsi</label><input id="province" name="province"></div>'
            . '<div><label for="city">Kota/Kabupaten</label><input id="city" name="city"></div>'
            . '<div><label for="year">Tahun</label><input id="year" name="year" type="number" min="0"></div>'
            . '</div><button type="submit">Cari website</button></form>';
    }

    /**
     * Form "Crawl URL tunggal": untuk permintaan crawl satu halaman/berkas
     * tertentu tanpa pencarian domain. URL-nya ditulis ke config/sites.json
     * (menggantikan isi sebelumnya) lalu bisa langsung di-crawl.
     */
    public static function singleUrlForm(): string
    {
        return '<form method="post" action="index.php">'
            . '<input type="hidden" name="aksi" value="url_single">'
            . '<div class="grid-form">'
            . '<div><label for="url_single">URL</label><input id="url_single" name="url_single" placeholder="https://ppid.kemendagri.go.id/" required>'
            . '<p class="muted" style="margin:6px 0 0">Isi satu URL http/https. URL ini akan menggantikan daftar web di config/sites.json, '
            . 'lalu tinggal tekan <em>Mulai crawl</em>.</p></div>'
            . '<div><label for="document_type">Jenis dokumen</label><input id="document_type" name="document_type" placeholder="APBD"></div>'
            . '<div><label for="province">Provinsi</label><input id="province" name="province"></div>'
            . '<div><label for="city">Kota/Kabupaten</label><input id="city" name="city"></div>'
            . '<div><label for="year">Tahun</label><input id="year" name="year" type="number" min="0"></div>'
            . '</div><button type="submit">Gunakan URL ini</button></form>';
    }

    /**
     * Panel riwayat crawl dari MySQL (status per website).
     *
     * @param list<array<string, mixed>> $rows
     * @param array{success: int, failed_akses: int, discovered: int, total: int} $stats
     */
    public static function crawlStorePanel(array $rows, array $stats, string $filter = ''): string
    {
        $filterLink = static function (string $status, string $label) use ($filter): string {
            $active = $filter === $status ? ' nav-link-active' : '';

            return '<a class="nav-link' . $active . '" href="index.php'
                . ($status === '' ? '' : '?store=' . rawurlencode($status)) . '">' . Ui::e($label) . '</a>';
        };

        $html = '<div class="panel" id="riwayat"><h2>Riwayat crawl (MySQL)</h2>'
            . '<div class="cards">'
            . Ui::statCard('Total website', (string) $stats['total'])
            . Ui::statCard('Sukses', (string) $stats['success'], 'dilewati otomatis pada run berikutnya')
            . Ui::statCard('Failed akses', (string) $stats['failed_akses'], 'website tidak bisa diakses')
            . Ui::statCard('Discovered', (string) ($stats['discovered'] ?? 0), 'hasil pencarian domain, belum di-crawl')
            . '</div>'
            . '<div class="form-row checks" style="margin:10px 0">'
            . $filterLink('', 'Semua')
            . $filterLink('success', 'Sukses')
            . $filterLink('failed_akses', 'Failed akses')
            . $filterLink('discovered', 'Discovered')
            . '</div>';

        if ($rows === []) {
            return $html . '<p class="muted">Belum ada riwayat crawl. Riwayat terisi otomatis setelah crawl selesai '
                . 'atau setelah pencarian domain (butuh MySQL Laragon aktif; setelan MYSQL_* pada .env).</p></div>';
        }

        $adaDiscovered = false;
        $body = '';
        foreach ($rows as $row) {
            $badge = match ($row['status'] ?? '') {
                'failed_akses' => '<span class="badge bad">failed akses</span>',
                'discovered' => '<span class="badge warn">discovered</span>',
                default => '<span class="badge ok">success</span>',
            };

            $pilih = '';
            if (($row['status'] ?? '') === 'discovered') {
                $adaDiscovered = true;
                $pilih = '<input type="checkbox" name="urls[]" value="' . Ui::e((string) ($row['url'] ?? '')) . '">';
            }

            $body .= '<tr><td>' . $pilih . '</td>'
                . '<td>' . $badge . '</td>'
                . '<td>' . Ui::e((string) ($row['url'] ?? '-')) . '</td>'
                . '<td>' . Ui::e((string) ($row['domain'] ?? '-')) . '</td>'
                . '<td>' . Ui::e((string) ($row['province'] ?? '-')) . '</td>'
                . '<td>' . Ui::e((string) ($row['city'] ?? '-')) . '</td>'
                . '<td>' . Ui::e((string) ($row['year'] ?? '-')) . '</td>'
                . '<td>' . Ui::e((string) ($row['message'] ?? '-')) . '</td>'
                . '<td>' . Ui::e((string) ($row['last_crawled'] ?? '-')) . '</td></tr>';
        }

        $tombol = $adaDiscovered
            ? '<div class="form-row checks" style="margin-top:10px">'
                . '<button type="submit" name="semua" value="0">Mulai crawl yang dipilih</button>'
                . '<button type="submit" name="semua" value="1">Mulai crawl SEMUA discovered</button>'
                . '</div>'
            : '';

        return $html
            . '<form method="post" action="index.php">'
            . '<input type="hidden" name="aksi" value="crawl_discovered">'
            . '<table><thead><tr><th>Pilih</th><th>Status</th><th>URL</th><th>Domain</th><th>Provinsi</th>'
            . '<th>Kota</th><th>Tahun</th><th>Keterangan</th><th>Terakhir di-crawl</th></tr></thead>'
            . '<tbody>' . $body . '</tbody></table>'
            . $tombol
            . '</form>'
            . '<p class="muted" style="margin-top:10px">Menampilkan ' . count($rows) . ' baris terakhir. '
            . 'Centang baris <em>discovered</em> lalu tekan tombol di atas untuk menulisnya ke config/sites.json '
            . 'dan langsung memulai crawl. Website berstatus <em>success</em> otomatis dilewati pada run berikutnya '
            . '(CRAWLER_MYSQL_SKIP_SUCCESS=true; paksa dengan opsi --re-crawl).</p></div>';
    }

    /**
     * Panel "Log pencarian domain" — hasil & log penemuan domain dari MySQL.
     *
     * @param list<array<string, mixed>> $logs
     */
    public static function discoveryLogPanel(array $logs): string
    {
        $html = '<div class="panel" id="log-domain"><h2>Log pencarian domain (MySQL)</h2>';

        if ($logs === []) {
            return $html . '<p class="muted">Belum ada pencarian domain. Hasil pencarian (beserta semua log) '
                . 'akan tampil di sini setelah menu "Cari website berdasarkan domain" dijalankan.</p></div>';
        }

        $body = '';

        foreach ($logs as $log) {
            $detail = json_decode((string) ($log['log'] ?? '[]'), true);
            $ringkas = is_array($detail) && isset($detail['situs'])
                ? implode(', ', array_column($detail['situs'], 'host'))
                : '-';

            $body .= '<tr><td>' . Ui::e((string) ($log['domain'] ?? '-')) . '</td>'
                . '<td>' . (int) ($log['jumlah_ditemukan'] ?? 0) . '</td>'
                . '<td>' . (int) ($log['jumlah_kandidat'] ?? 0) . '</td>'
                . '<td>' . Ui::e((string) ($log['created_at'] ?? '-')) . '</td>'
                . '<td class="muted" style="font-size:12px">' . Ui::e(Text::oneLine($ringkas, 160)) . '</td></tr>';
        }

        return $html . '<table><thead><tr><th>Domain</th><th>Ditemukan</th><th>Kandidat</th>'
            . '<th>Waktu</th><th>Host yang ditemukan</th></tr></thead>'
            . '<tbody>' . $body . '</tbody></table>'
            . '<p class="muted" style="margin-top:10px">Menampilkan ' . count($logs) . ' pencarian terakhir. '
            . 'Rincian lengkap setiap pencarian tersimpan di tabel <code>discovery_logs</code>.</p></div>';
    }

    /**
     * Panel "Per website" untuk satu run: Web | Dokumen | Chunk | Vektor.
     *
     * @param list<array<string, mixed>> $baris
     * @param array<string, int>         $total
     */
    public static function perSitePanel(array $baris, array $total, string $runId, bool $aktif): string
    {
        $badge = $aktif ? '<span class="badge run">BERJALAN</span>' : '<span class="badge ok">SELESAI</span>';

        $html = '<div class="panel" id="per-web"><h2>Per website — ' . Ui::e($runId) . ' ' . $badge . '</h2>'
            . '<div class="cards">'
            . Ui::statCard('Dokumen', (string) ($total['dokumen'] ?? 0), 'sudah di-parse & di-embed')
            . Ui::statCard('Chunk', (string) ($total['chunk'] ?? 0), 'potongan teks yang dibentuk')
            . Ui::statCard('Vektor', (string) ($total['vektor'] ?? 0), 'baris vektor tersimpan')
            . '</div>';

        if ($baris === []) {
            return $html . '<p class="muted">Belum ada dokumen/vektor pada run ini. Angka muncul begitu dokumen '
                . 'pertama selesai di-parse &amp; di-embed.</p></div>';
        }

        $body = '';
        foreach ($baris as $row) {
            $host = (string) ($row['host'] ?? '');

            $body .= '<tr><td>' . Ui::e((string) $row['site'])
                . ($host !== '' && $host !== (string) $row['site'] ? '<div class="muted">' . Ui::e($host) . '</div>' : '')
                . '</td>'
                . '<td>' . (int) $row['dokumen'] . '</td>'
                . '<td>' . (int) $row['chunk'] . '</td>'
                . '<td>' . (int) $row['vektor'] . '</td></tr>';
        }

        return $html
            . '<div style="overflow:auto;max-height:420px">'
            . '<table><thead><tr><th>Web</th><th>Dokumen</th><th>Chunk</th><th>Vektor</th></tr></thead>'
            . '<tbody>' . $body . '</tbody>'
            . '<tfoot><tr><th>TOTAL</th><th>' . (int) ($total['dokumen'] ?? 0) . '</th><th>'
            . (int) ($total['chunk'] ?? 0) . '</th><th>' . (int) ($total['vektor'] ?? 0) . '</th></tr></tfoot>'
            . '</table></div>'
            . '<p class="muted" style="margin-top:10px">Dokumen = dokumen yang sudah selesai di-parse &amp; di-embed; '
            . 'Chunk = potongan teks yang dibentuk; Vektor = baris berkas vektor (1 baris = 1 chunk tervektor). '
            . 'Angka bertambah terus selama run berjalan (halaman menyegar sendiri tiap 5 detik).</p></div>';
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
