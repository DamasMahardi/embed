<?php

declare(strict_types=1);

/**
 * Halaman ESTIMASI: perkiraan lama crawl SEBELUM run.
 *
 * Angka dikalibrasi dari riwayat run (logs/runs/*.txt): waktu unduh per
 * halaman, waktu /parse per berkas, waktu /embed per vektor, dan rasio
 * vektor/halaman. Konstanta hasil kalibrasi juga ditampilkan agar perkiraan
 * bisa dinilai sendiri.
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\Crawl\SiteRepository;
use App\Report\CrawlEstimator;
use App\Support\Config;
use App\Support\Text;
use App\Ui\Ui;

$config = Config::app();
Ui::guard($config['ui'] ?? []);

$estimator = new CrawlEstimator($config['paths'] ?? []);

$halaman = max(0, (int) ($_GET['halaman'] ?? 0));
$modeRender = (string) ($_GET['mode_render'] ?? 'auto');
$modeRender = in_array($modeRender, ['auto', 'always', 'off'], true) ? $modeRender : 'auto';

$semuaSite = [];
try {
    $semuaSite = (new SiteRepository())->all();
} catch (Throwable) {
    $semuaSite = [];
}

$dipilih = array_values(array_filter(
    array_map('strval', (array) ($_GET['site'] ?? [])),
    static fn (string $id): bool => $id !== ''
));

$sites = [];
foreach ($semuaSite as $site) {
    if ($dipilih === [] ? $site->enabled : in_array($site->id, $dipilih, true)) {
        $sites[] = $site;
    }
}

$out = [];
$out[] = '<h1>Estimasi lama crawl</h1>'
    . '<p class="muted">Perkiraan waktu sebelum menjalankan crawl, dihitung dari pola run sebelumnya '
    . '(waktu unduh per halaman, waktu <code>/parse</code> per berkas, waktu <code>/embed</code> per vektor).</p>';

// ------------------------------------------------------------------ form
$checkboxes = '';
foreach ($semuaSite as $site) {
    $checkboxes .= '<label class="check"><input type="checkbox" name="site[]" value="' . Ui::e($site->id) . '"'
        . (in_array($site->id, $dipilih, true) ? ' checked' : '') . '> <span>' . Ui::e($site->id)
        . ' &middot; ' . Ui::e(implode(', ', $site->startUrls)) . '</span></label>';
}

if ($semuaSite === []) {
    $checkboxes = '<p class="muted">Belum ada site pada config/sites.json.</p>';
}

$renderOptions = '';
foreach ([
    'auto' => 'auto - hanya halaman kerangka JavaScript (bawaan)',
    'always' => 'always - semua halaman dirender browser (paling lama)',
    'off' => 'off - tanpa render browser',
] as $nilai => $label) {
    $renderOptions .= '<option value="' . $nilai . '"' . ($modeRender === $nilai ? ' selected' : '') . '>'
        . Ui::e($label) . '</option>';
}

$out[] = '<form method="get" action="estimate.php" class="panel">'
    . '<div class="form-row"><label>Web yang diperkirakan</label><div class="checks">' . $checkboxes . '</div>'
    . '<p class="muted" style="margin:6px 0 0">Kosongkan semua centang untuk memakai seluruh site aktif.</p></div>'
    . '<div class="grid-form">'
    . '<div><label for="halaman">Perkiraan jumlah halaman / web</label>'
    . '<input type="number" id="halaman" name="halaman" min="0" value="' . $halaman . '">'
    . '<p class="muted" style="margin:6px 0 0">0 = pakai rata-rata riwayat web itu (halaman per run).</p></div>'
    . '<div><label for="mode_render">Render JavaScript</label><select id="mode_render" name="mode_render">'
    . $renderOptions . '</select></div>'
    . '</div><div class="form-row checks"><button type="submit">Hitung estimasi</button>'
    . '<a class="nav-link" href="estimate.php">Reset</a></div></form>';

if ($sites === []) {
    $out[] = '<div class="panel"><p class="muted">Tidak ada site yang bisa diperkirakan.</p></div>';
    echo Ui::layout('Estimasi', 'estimate', implode(PHP_EOL, $out));
    exit;
}

// ------------------------------------------------------------- perhitungan
$hasil = $estimator->estimate($sites, ['halaman' => $halaman, 'mode_render' => $modeRender]);
$total = $hasil['total'];

$out[] = '<div class="cards">'
    . Ui::statCard('Perkiraan total', Text::duration((float) $total['detik_total']), $total['halaman'] . ' halaman (semua web)')
    . Ui::statCard('Unduh + parse', Text::duration((float) ($total['detik_unduh'] + $total['detik_parse'])), 'tahap jaringan & /parse')
    . Ui::statCard('Render JS', Text::duration((float) $total['detik_render']), 'mode ' . $modeRender)
    . Ui::statCard('Embed /embed', Text::duration((float) $total['detik_embed']), 'perkiraan ' . $total['vektor'] . ' vektor')
    . Ui::statCard('Dokumen', (string) $total['dokumen'], 'perkiraan berkas dokumen yang diunduh')
    . '</div>';

$rows = '';
foreach ($hasil['baris'] as $row) {
    $rows .= '<tr><td>' . Ui::e((string) $row['site']) . '<div class="muted">' . Ui::e((string) $row['nama']) . '</div></td>'
        . '<td>' . (int) $row['halaman'] . '</td>'
        . '<td>' . (int) $row['dokumen'] . '</td>'
        . '<td>' . (int) $row['vektor'] . '</td>'
        . '<td>' . Text::duration((float) $row['detik_per_url']) . '</td>'
        . '<td>' . Text::duration((float) $row['detik_unduh']) . '</td>'
        . '<td>' . Text::duration((float) $row['detik_parse']) . '</td>'
        . '<td>' . Text::duration((float) $row['detik_render']) . '</td>'
        . '<td>' . Text::duration((float) $row['detik_embed']) . '</td>'
        . '<td><strong>' . Text::duration((float) $row['detik_total']) . '</strong></td>'
        . '<td>' . Ui::e((string) $row['sumber']) . ' (' . (int) $row['sampel'] . ' run)</td></tr>';
}

$out[] = '<div class="panel"><h2>Perkiraan per web</h2>'
    . ($hasil['yakin'] ? '' : '<p><span class="badge warn">kalibrasi masih sedikit (' . (int) $hasil['sampel'] . ' run); anggap angka ini kasar</span></p>')
    . '<table><thead><tr><th>Web</th><th>Halaman</th><th>Dokumen</th><th>Vektor</th><th>Detik/halaman</th>'
    . '<th>Unduh</th><th>Parse</th><th>Render</th><th>Embed</th><th>Total</th><th>Sumber kalibrasi</th></tr></thead>'
    . '<tbody>' . $rows . '</tbody></table>'
    . '<p class="muted" style="margin-top:10px">Total per baris = perkiraan lama satu run untuk web itu. '
    . 'Untuk beberapa web, angkanya dijumlahkan (crawl dijalankan berurutan).</p></div>';

$kalibrasi = $estimator->calibration();
$global = $kalibrasi['global'];

$konstanta = '<ul class="plain">';
foreach ([
    'detik_unduh' => 'detik per unduhan halaman (jaringan + tulis berkas)',
    'detik_parse' => 'detik per permintaan /parse',
    'detik_embed' => 'detik per vektor pada /embed',
    'detik_render' => 'detik per halaman yang dirender browser',
    'vektor_per_url' => 'vektor per halaman',
    'dokumen_per_url' => 'berkas dokumen per halaman',
    'porsi_render' => 'porsi halaman yang perlu render (mode auto)',
    'halaman_per_run' => 'halaman per run (riwayat)',
] as $kunci => $keterangan) {
    if (!isset($global[$kunci])) {
        continue;
    }

    $konstanta .= '<li><strong>' . Ui::e($kunci) . '</strong>: '
        . Ui::e(number_format((float) $global[$kunci], 3, ',', '.')) . ' <span class="muted">' . Ui::e($keterangan) . '</span></li>';
}

$konstanta .= '</ul>';

$out[] = '<div class="panel"><h2>Konstanta kalibrasi (global)</h2>' . $konstanta
    . '<p class="muted">Dihitung dari ' . (int) $kalibrasi['sampel'] . ' log run pada logs/runs/. '
    . 'Semakin banyak riwayat run, semakin dekat perkiraannya.</p></div>';

$asumsi = '<ul class="plain">';
foreach ($hasil['asumsi'] as $baris) {
    $asumsi .= '<li>' . Ui::e($baris) . '</li>';
}
$asumsi .= '</ul>';

$out[] = '<div class="panel"><h2>Asumsi</h2>' . $asumsi . '</div>';

echo Ui::layout('Estimasi', 'estimate', implode(PHP_EOL, $out));
