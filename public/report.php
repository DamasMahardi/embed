<?php

declare(strict_types=1);

/**
 * Halaman REPORT: rekap Dokumen / Chunk / Vektor per TANGGAL dan per WEB, plus
 * pemantauan dokumen yang sudah di-crawl pada sebuah run (termasuk saat job
 * masih berjalan).
 *
 * Sumber: storage/vectors/<site>/<run>.manifest.json (rekap), <run>.jsonl
 * (jumlah chunk tervektor; ikut bertambah saat run berjalan), dan
 * logs/runs/<run>.txt (baris DOC_AFTER per dokumen).
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\Report\CrawlReport;
use App\Support\Config;
use App\Support\Text;
use App\Ui\Ui;

$config = Config::app();
Ui::guard($config['ui'] ?? []);

$report = new CrawlReport($config['paths'] ?? []);

$dari = trim((string) ($_GET['dari'] ?? date('Y-m-d')));
$sampai = trim((string) ($_GET['sampai'] ?? date('Y-m-d')));
$siteFilter = trim((string) ($_GET['site'] ?? ''));
$runId = trim((string) ($_GET['run'] ?? ''));

// Bila tidak ada run yang dipilih, pakai run TERBARU supaya angka
// Dokumen/Chunk/Vektor per website langsung terlihat (termasuk saat berjalan).
$runOtomatis = false;

if ($runId === '') {
    $runId = $report->latestRunId();
    $runOtomatis = $runId !== '';
}

$ringkasan = $report->summary($dari, $sampai, $siteFilter);
$baris = $ringkasan['baris'];
$total = $ringkasan['total'];
$siteOptions = $report->siteOptions();

// ---------------------------------------------------------------- ekspor CSV
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report-crawl-' . $dari . '_' . $sampai . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['tanggal', 'site_id', 'nama_web', 'run', 'dokumen', 'chunk', 'vektor', 'push_terkirim']);

    foreach ($baris as $row) {
        fputcsv($out, [
            $row['tanggal'],
            $row['site_id'],
            $row['site_name'],
            $row['run'],
            $row['dokumen'],
            $row['chunk'],
            $row['vektor'],
            $row['push_terkirim'],
        ]);
    }

    fputcsv($out, ['TOTAL', '', '', $total['run'], $total['dokumen'], $total['chunk'], $total['vektor'], $total['push_terkirim']]);
    fclose($out);
    exit;
}

// ------------------------------------------------------------------ pemantauan
$headExtra = '';
$out = [];
$live = null;
$dokumenRun = [];

if ($runId !== '') {
    $live = $report->live($runId);
    $dokumenRun = $report->documents($runId);

    if ($live['aktif']) {
        $headExtra = '<meta http-equiv="refresh" content="10">';
    }
}

// ---------------------------------------------------------------------- form
$siteSelect = '<option value="">(semua web)</option>';
foreach ($siteOptions as $id => $nama) {
    $siteSelect .= '<option value="' . Ui::e($id) . '"' . ($siteFilter === $id ? ' selected' : '') . '>'
        . Ui::e($id) . ' &middot; ' . Ui::e($nama) . '</option>';
}

$out[] = '<h1>Report crawl</h1>'
    . '<form method="get" action="report.php" class="panel"><div class="grid-form">'
    . '<div><label for="dari">Tanggal dari</label><input type="date" id="dari" name="dari" value="' . Ui::e($dari) . '"></div>'
    . '<div><label for="sampai">Tanggal sampai</label><input type="date" id="sampai" name="sampai" value="' . Ui::e($sampai) . '"></div>'
    . '<div><label for="site">Web</label><select id="site" name="site">' . $siteSelect . '</select></div>'
    . '</div><div class="form-row checks"><button type="submit">Terapkan filter</button>'
    . '<a class="nav-link" href="report.php?dari=' . Ui::e(date('Y-m-d')) . '&amp;sampai=' . Ui::e(date('Y-m-d')) . '">Hari ini</a>'
    . '<a class="nav-link" href="report.php?dari=' . Ui::e(date('Y-m-d', strtotime('-6 days'))) . '&amp;sampai=' . Ui::e(date('Y-m-d')) . '">7 hari</a>'
    . '<a class="nav-link" href="report.php?dari=' . Ui::e(date('Y-m-01')) . '&amp;sampai=' . Ui::e(date('Y-m-d')) . '">Bulan ini</a>'
    . '<a class="nav-link" href="report.php?dari=&amp;sampai=&amp;site=' . Ui::e($siteFilter) . '">Semua tanggal</a>'
    . '<a class="nav-link" href="report.php?dari=' . Ui::e($dari) . '&amp;sampai=' . Ui::e($sampai) . '&amp;site=' . Ui::e($siteFilter) . '&amp;export=csv">Unduh CSV</a>'
    . '</div></form>';

$out[] = '<div class="cards">'
    . Ui::statCard('Run', (string) $total['run'], $dari . ' s/d ' . $sampai)
    . Ui::statCard('Dokumen', (string) $total['dokumen'], 'halaman + berkas dokumen yang menghasilkan vektor')
    . Ui::statCard('Chunk', (string) $total['chunk'], 'potongan teks yang dibentuk')
    . Ui::statCard('Vektor', (string) $total['vektor'], 'point siap/terkirim ke Qdrant')
    . Ui::statCard('Point terkirim', (string) $total['push_terkirim'], 'push otomatis Qdrant')
    . '</div>';

// -------------------------------------------------------------- tabel rekap
if ($baris === []) {
    $out[] = '<div class="panel"><p class="muted">Belum ada run pada rentang tanggal ini. '
        . 'Angka diambil dari manifest (storage/vectors/&lt;site&gt;/&lt;run&gt;.manifest.json) atau, '
        . 'bila manifest belum tertulis, dari logs/runs/&lt;run&gt;.txt dan storage/vectors/&lt;site&gt;/&lt;run&gt;.jsonl.</p></div>';
} else {
    $rows = '';
    foreach ($baris as $row) {
        $runs = '';
        foreach ($row['runs'] as $run) {
            $runs .= '<li><a href="report.php?dari=' . Ui::e($dari) . '&amp;sampai=' . Ui::e($sampai)
                . '&amp;site=' . Ui::e($siteFilter) . '&amp;run=' . Ui::e($run['run_id']) . '">'
                . Ui::e($run['run_id']) . '</a> <span class="muted">' . Ui::e(substr((string) $run['dibuat'], 11))
                . ' &middot; ' . (int) $run['dokumen'] . ' dok / ' . (int) $run['chunk'] . ' chunk / ' . (int) $run['vektor'] . ' vektor'
                . ' &middot; push ' . Ui::e($run['push'])
                . (($run['tanpa_manifest'] ?? false) ? ' &middot; dari log/jsonl' : '')
                . ($run['aktif'] ? ' &middot; <strong>sedang berjalan</strong>' : '') . '</span></li>';
        }

        $rows .= '<tr><td>' . Ui::e($row['tanggal']) . '</td>'
            . '<td>' . Ui::e($row['site_id']) . '<div class="muted">' . Ui::e($row['site_name']) . '</div></td>'
            . '<td>' . (int) $row['run'] . '</td>'
            . '<td>' . (int) $row['dokumen'] . '</td>'
            . '<td>' . (int) $row['chunk'] . '</td>'
            . '<td>' . (int) $row['vektor'] . '</td>'
            . '<td>' . (int) $row['push_terkirim'] . '</td>'
            . '<td><ul class="plain">' . $runs . '</ul></td></tr>';
    }

    $out[] = '<div class="panel"><h2>Rekap per tanggal &amp; web</h2>'
        . '<table><thead><tr><th>Tanggal</th><th>Web</th><th>Run</th><th>Dokumen</th><th>Chunk</th><th>Vektor</th><th>Push</th>'
        . '<th>Run (klik untuk melihat dokumen)</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table>'
        . '<p class="muted" style="margin-top:10px">Chunk = potongan teks yang di-embed; Vektor = baris berkas vektor '
        . '(1 baris = 1 chunk + 1 vektor). Manifest run lama belum memuat angka chunk sehingga dihitung dari berkas .jsonl.</p></div>';
}

// ------------------------------------------------- pemantauan dokumen per run
if ($runId !== '' && $live !== null) {
    $statusLive = $live['selesai']
        ? '<span class="badge ok">SELESAI</span>'
        : ($live['aktif'] ? '<span class="badge run">BERJALAN</span>' : '<span class="badge idle">TIDAK AKTIF</span>');

    $rowsRun = '';

    foreach ($dokumenRun as $dokumen) {
        $badge = match ($dokumen['status']) {
            'SUKSES' => '<span class="badge ok">SUKSES</span>',
            'DILEWATI' => '<span class="badge warn">DILEWATI</span>',
            default => '<span class="badge bad">' . Ui::e((string) $dokumen['status']) . '</span>',
        };

        $rowsRun .= '<tr><td>' . $badge . '</td>'
            . '<td>' . Ui::e((string) $dokumen['document_id']) . '</td>'
            . '<td>' . Ui::e(Text::oneLine((string) $dokumen['judul'], 70)) . '</td>'
            . '<td>' . Ui::e(Text::oneLine((string) $dokumen['url'], 90)) . '</td>'
            . '<td>' . Ui::e((string) $dokumen['mode_parse']) . '</td>'
            . '<td>' . (int) $dokumen['chunk'] . '</td>'
            . '<td>' . (int) $dokumen['vektor'] . '</td></tr>';
    }

    if ($rowsRun === '') {
        $rowsRun = '<tr><td colspan="7" class="muted">Belum ada dokumen selesai pada run ini '
            . '(baris DOC_AFTER muncul begitu satu dokumen selesai diproses).</td></tr>';
    }

    // Tabel per WEBSITE: Web | Dokumen | Chunk | Vektor (angka run ini).
    $perSiteRun = $report->perSiteRun($runId);
    $rowsSite = '';

    foreach ($perSiteRun['baris'] as $rowSite) {
        $hostSite = (string) ($rowSite['host'] ?? '');

        $rowsSite .= '<tr><td>' . Ui::e((string) $rowSite['site'])
            . ($hostSite !== '' && $hostSite !== (string) $rowSite['site'] ? '<div class="muted">' . Ui::e($hostSite) . '</div>' : '')
            . '</td>'
            . '<td>' . (int) $rowSite['dokumen'] . '</td>'
            . '<td>' . (int) $rowSite['chunk'] . '</td>'
            . '<td>' . (int) $rowSite['vektor'] . '</td></tr>';
    }

    if ($rowsSite === '') {
        $rowsSite = '<tr><td colspan="4" class="muted">Belum ada dokumen pada run ini.</td></tr>';
    }

    $out[] = '<div class="panel" id="per-web"><h2>Per website (Dokumen / Chunk / Vektor)</h2>'
        . '<div class="cards">'
        . Ui::statCard('Dokumen', (string) $perSiteRun['total']['dokumen'], 'sudah di-parse & di-embed')
        . Ui::statCard('Chunk', (string) $perSiteRun['total']['chunk'], 'potongan teks yang dibentuk')
        . Ui::statCard('Vektor', (string) $perSiteRun['total']['vektor'], 'baris vektor tersimpan')
        . '</div>'
        . '<div style="overflow:auto;max-height:420px">'
        . '<table><thead><tr><th>Web</th><th>Dokumen</th><th>Chunk</th><th>Vektor</th></tr></thead>'
        . '<tbody>' . $rowsSite . '</tbody>'
        . '<tfoot><tr><th>TOTAL</th><th>' . (int) $perSiteRun['total']['dokumen'] . '</th><th>'
        . (int) $perSiteRun['total']['chunk'] . '</th><th>' . (int) $perSiteRun['total']['vektor'] . '</th></tr></tfoot>'
        . '</table></div>'
        . '<p class="muted" style="margin-top:10px">Run ' . Ui::e($runId)
        . ($runOtomatis ? ' (run terbaru, dipilih otomatis)' : '')
        . ' &mdash; angkanya bertambah selama run berjalan.</p></div>';

    $out[] = '<div class="panel" id="run"><h2>Pemantauan run ' . Ui::e($runId) . ' ' . $statusLive . '</h2>'
        . '<div class="cards">'
        . Ui::statCard('Dokumen selesai', (string) count($dokumenRun))
        . Ui::statCard('Chunk tervektor', (string) $live['chunk_vektor'], 'dihitung dari berkas .jsonl (bertambah saat run berjalan)')
        . '</div>'
        . ($live['aktif'] ? '<p class="muted">Halaman ini menyegar sendiri setiap 10 detik selama run berjalan.</p>' : '')
        . '<table><thead><tr><th>Status</th><th>Document ID</th><th>Judul</th><th>URL</th><th>Mode parse</th><th>Chunk</th><th>Vektor</th></tr></thead>'
        . '<tbody>' . $rowsRun . '</tbody></table>'
        . ($live['log'] !== '' ? '<p class="muted" style="margin-top:10px">Log run: '
            . Ui::logLink($live['log']) . ' &middot; <a href="logs.php?file='
            . rawurlencode(Ui::relPath($live['log'])) . '">lihat di halaman Log</a></p>' : '')
        . '</div>';
}

echo Ui::layout('Report', 'report', implode(PHP_EOL, $out), $headExtra);
