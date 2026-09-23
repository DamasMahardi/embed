<?php

declare(strict_types=1);

namespace App\Report;

use App\Crawl\SiteConfig;

/**
 * Estimasi lama crawl SEBELUM run, dikalibrasi dari riwayat run nyata.
 *
 * Sumber kalibrasi: logs/runs/<run>.txt. Dari tiap run diambil:
 *   Durasi total, URL diunduh OK, Dokumen tersimpan, Vektor berhasil (blok
 *   RINGKASAN) + total durasi PARSE_AFTER, EMBED_BATCH, dan RENDER_AFTER.
 *
 * Konstanta terukur yang dihasilkan:
 *   detik_unduh  = (durasi total - parse - embed - render) / URL diunduh
 *   detik_parse  = total parse / jumlah parse
 *   detik_embed  = total embed / jumlah vektor
 *   detik_render = total render / jumlah render
 *   vektor_per_url, dokumen_per_url, halaman_per_run, porsi_render
 *
 * Estimasi = halaman x (unduh + parse + porsi render)
 *            + (halaman x vektor_per_url) x detik_embed.
 * Semua angka adalah PERKIRAAN berbasis pola run sebelumnya, bukan janji.
 */
final class CrawlEstimator
{
    /** Nilai cadangan bila belum ada riwayat run (perkiraan kasar). */
    private const DEFAULTS = [
        'detik_unduh' => 2.5,
        'detik_parse' => 12.0,
        'detik_embed' => 1.2,
        'detik_render' => 6.0,
        'vektor_per_url' => 6.0,
        'dokumen_per_url' => 0.3,
        'porsi_render' => 0.2,
        'halaman_per_run' => 100.0,
    ];

    /** @param array<string, mixed> $paths bagian "paths" pada config/app.php */
    public function __construct(private array $paths)
    {
    }

    /**
     * Baca log run dan hitung konstanta per site + global.
     *
     * @return array{global: array<string, float>, sites: array<string, array<string, float>>, sampel: int, sampel_site: array<string, int>}
     */
    public function calibration(int $maxRuns = 60): array
    {
        $dir = (string) ($this->paths['runs'] ?? base_path('logs/runs'));
        $files = glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '*.txt') ?: [];

        // Log terbaru lebih relevan: urutkan menurun, ambil maksimal $maxRuns.
        usort($files, static fn (string $a, string $b): int => (int) @filemtime($b) <=> (int) @filemtime($a));
        $files = array_slice($files, 0, max(1, $maxRuns));

        $kumpulan = [];
        $global = [];
        $sudahDihitung = [];

        foreach ($files as $file) {
            $angka = $this->runMetrics($file);

            if ($angka === null) {
                continue;
            }

            $sudahDihitung[basename($file, '.txt')] = true;
            $global[] = $angka;
            $kumpulan[(string) $angka['site']][] = $angka;
        }

        // Log per run sering dibersihkan dari dashboard. Bila sampelnya masih
        // sedikit, log HARIAN (logs/crawl-*.txt) dipakai sebagai sumber kedua:
        // satu log harian memuat banyak run sehingga kalibrasi tetap terisi.
        if (count($global) < 3) {
            foreach ($this->dailyMetrics($sudahDihitung) as $angka) {
                $global[] = $angka;
                $kumpulan[(string) $angka['site']][] = $angka;
            }
        }

        return [
            'global' => $this->average($global),
            'sites' => array_map(fn (array $daftar): array => $this->average($daftar), $kumpulan),
            'sampel' => count($global),
            'sampel_site' => array_map('count', $kumpulan),
        ];
    }

    /**
     * Angka per RUN dari log harian (logs/crawl-YYYY-MM-DD.txt).
     *
     * Dipakai bila log per run sudah dibersihkan. Durasi run dihitung dari
     * selisih stempel waktu RUN_START/SITE_START -> RUN_END; jumlah unduhan &
     * dokumen dari baris FETCH_AFTER/DOC_AFTER; total tahap dari durasi= pada
     * PARSE_AFTER/EMBED_BATCH/RENDER_AFTER.
     *
     * @param array<string, bool> $lewati id run yang sudah dihitung dari logs/runs
     *
     * @return list<array<string, mixed>>
     */
    private function dailyMetrics(array $lewati = [], int $maxFiles = 3, int $maxBytes = 8_000_000): array
    {
        $dir = (string) ($this->paths['logs'] ?? base_path('logs'));
        $files = glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'crawl-*.txt') ?: [];

        usort($files, static fn (string $a, string $b): int => (int) @filemtime($b) <=> (int) @filemtime($a));
        $files = array_slice($files, 0, max(1, $maxFiles));

        $runs = [];
        $pola = '/^\[([\d\-: ]+)\] \[(RUN-\d{8}-\d{6})\] \[([^\]]*)\] \[([A-Z_]+)\]/';

        foreach ($files as $file) {
            $handle = @fopen($file, 'rb');

            if ($handle === false) {
                continue;
            }

            $dibaca = 0;

            while (($line = fgets($handle)) !== false) {
                $dibaca += strlen($line);

                if ($dibaca > $maxBytes) {
                    break;
                }

                if (preg_match($pola, $line, $c) !== 1) {
                    continue;
                }

                $run = $c[2];
                $tahap = $c[4];

                if (isset($lewati[$run])) {
                    continue;
                }

                if (!isset($runs[$run])) {
                    $runs[$run] = [
                        'site' => $c[3], 'mulai' => (int) strtotime($c[1]), 'akhir' => 0,
                        'unduh' => 0, 'dokumen' => 0, 'vektor' => 0,
                        'parse' => 0.0, 'parse_n' => 0,
                        'embed' => 0.0, 'embed_n' => 0,
                        'render' => 0.0, 'render_n' => 0,
                    ];
                }

                if (in_array($tahap, ['RUN_START', 'SITE_START'], true)) {
                    $runs[$run]['mulai'] = (int) strtotime($c[1]);
                }

                if ($tahap === 'RUN_END') {
                    $runs[$run]['akhir'] = (int) strtotime($c[1]);
                }

                if ($tahap === 'FETCH_AFTER' && str_contains($line, 'status=SUKSES')) {
                    $runs[$run]['unduh']++;
                }

                if ($tahap === 'DOC_AFTER' && str_contains($line, 'status=SUKSES')) {
                    $runs[$run]['dokumen']++;
                }

                if ($tahap === 'EMBED_BATCH' && preg_match('/vektor=(\d+)/', $line, $v) === 1) {
                    $runs[$run]['vektor'] += (int) $v[1];
                }

                if (preg_match('/durasi=([0-9.]+)s/', $line, $d) !== 1) {
                    continue;
                }

                $detik = (float) $d[1];

                if ($tahap === 'PARSE_AFTER') {
                    $runs[$run]['parse'] += $detik;
                    $runs[$run]['parse_n']++;
                } elseif ($tahap === 'EMBED_BATCH') {
                    $runs[$run]['embed'] += $detik;
                    $runs[$run]['embed_n'] += preg_match('/vektor=(\d+)/', $line, $v2) === 1 ? (int) $v2[1] : 0;
                } elseif ($tahap === 'RENDER_AFTER') {
                    $runs[$run]['render'] += $detik;
                    $runs[$run]['render_n']++;
                }
            }

            fclose($handle);
        }

        $hasil = [];

        foreach ($runs as $run) {
            $durasi = $run['akhir'] > $run['mulai'] ? (float) ($run['akhir'] - $run['mulai']) : 0.0;

            if ($durasi <= 0 || $run['unduh'] <= 0) {
                continue;
            }

            $halaman = (float) $run['unduh'];
            $lainnya = max(0.0, $durasi - $run['parse'] - $run['embed'] - $run['render']);

            $hasil[] = [
                'site' => $run['site'],
                'detik_unduh' => $lainnya / $halaman,
                'detik_parse' => $run['parse_n'] > 0 ? $run['parse'] / $run['parse_n'] : 0.0,
                'detik_embed' => $run['embed_n'] > 0 ? $run['embed'] / $run['embed_n'] : 0.0,
                'detik_render' => $run['render_n'] > 0 ? $run['render'] / $run['render_n'] : 0.0,
                'vektor_per_url' => $run['vektor'] / $halaman,
                'dokumen_per_url' => $run['dokumen'] / $halaman,
                'porsi_render' => $run['render_n'] / $halaman,
                'halaman_per_run' => $halaman,
            ];
        }

        return $hasil;
    }

    /**
     * Estimasi lama crawl untuk rencana run.
     *
     * @param list<SiteConfig>     $sites
     * @param array<string, mixed> $plan halaman, mode_render
     *
     * @return array{baris: list<array<string, mixed>>, total: array<string, float>, asumsi: list<string>, sampel: int, yakin: bool}
     */
    public function estimate(array $sites, array $plan = []): array
    {
        $kalibrasi = $this->calibration();
        $global = $kalibrasi['global'] === [] ? self::DEFAULTS : $kalibrasi['global'];
        $halamanDiminta = max(0, (int) ($plan['halaman'] ?? 0));
        $modeRender = (string) ($plan['mode_render'] ?? 'auto');

        $baris = [];

        foreach ($sites as $site) {
            $angka = $kalibrasi['sites'][$site->id] ?? $global;

            // Jumlah halaman: dari form; bila kosong pakai rata-rata riwayat site.
            $halaman = $halamanDiminta > 0
                ? $halamanDiminta
                : max(50, (int) round((float) ($angka['halaman_per_run'] ?? self::DEFAULTS['halaman_per_run'])));

            $porsiRender = $modeRender === 'always'
                ? 1.0
                : ($modeRender === 'off' ? 0.0 : (float) ($angka['porsi_render'] ?? self::DEFAULTS['porsi_render']));

            $detikUnduh = $halaman * (float) ($angka['detik_unduh'] ?? self::DEFAULTS['detik_unduh']);
            $detikParse = $halaman * (float) ($angka['detik_parse'] ?? self::DEFAULTS['detik_parse']);
            $detikRender = $halaman * $porsiRender * (float) ($angka['detik_render'] ?? 0.0);
            $dokumen = (int) round($halaman * (float) ($angka['dokumen_per_url'] ?? self::DEFAULTS['dokumen_per_url']));
            $vektor = (int) round($halaman * (float) ($angka['vektor_per_url'] ?? self::DEFAULTS['vektor_per_url']));
            $detikEmbed = $vektor * (float) ($angka['detik_embed'] ?? self::DEFAULTS['detik_embed']);

            $baris[] = [
                'site' => $site->id,
                'nama' => $site->name,
                'halaman' => $halaman,
                'dokumen' => $dokumen,
                'vektor' => $vektor,
                'detik_unduh' => $detikUnduh,
                'detik_parse' => $detikParse,
                'detik_render' => $detikRender,
                'detik_embed' => $detikEmbed,
                'detik_total' => $detikUnduh + $detikParse + $detikRender + $detikEmbed,
                'detik_per_url' => $halaman > 0 ? ($detikUnduh + $detikParse + $detikRender) / $halaman : 0.0,
                'sampel' => (int) ($kalibrasi['sampel_site'][$site->id] ?? 0),
                'sumber' => isset($kalibrasi['sites'][$site->id]) ? 'riwayat site' : 'riwayat global',
            ];
        }

        $total = [
            'halaman' => 0,
            'dokumen' => 0,
            'vektor' => 0,
            'detik_unduh' => 0.0,
            'detik_parse' => 0.0,
            'detik_render' => 0.0,
            'detik_embed' => 0.0,
            'detik_total' => 0.0,
        ];

        foreach ($baris as $row) {
            $total['halaman'] += (int) $row['halaman'];
            $total['dokumen'] += (int) $row['dokumen'];
            $total['vektor'] += (int) $row['vektor'];
            $total['detik_unduh'] += (float) $row['detik_unduh'];
            $total['detik_parse'] += (float) $row['detik_parse'];
            $total['detik_render'] += (float) $row['detik_render'];
            $total['detik_embed'] += (float) $row['detik_embed'];
            $total['detik_total'] += (float) $row['detik_total'];
        }

        return [
            'baris' => $baris,
            'total' => $total,
            'asumsi' => $this->assumptions($kalibrasi['sampel'], $modeRender),
            'sampel' => $kalibrasi['sampel'],
            'yakin' => $kalibrasi['sampel'] >= 3,
        ];
    }

    /**
     * Daftar asumsi yang ditampilkan di halaman Estimasi.
     *
     * @return list<string>
     */
    private function assumptions(int $sampel, string $modeRender): array
    {
        return [
            'Kalibrasi dari ' . $sampel . ' log run terakhir pada logs/runs/.',
            'detik/unduh = durasi non-parse/embed/render dibagi jumlah unduhan; detik/parse & detik/embed dijumlahkan dari log PARSE_AFTER / EMBED_BATCH.',
            'Jumlah vektor mengikuti pola riwayat (vektor per halaman); satu dokumen PDF besar bisa jauh lebih lama daripada satu halaman HTML.',
            'Mode render "' . $modeRender . '": ' . ($modeRender === 'always'
                ? 'seluruh halaman dirender (perkiraan paling lama).'
                : ($modeRender === 'off' ? 'tanpa render browser.' : 'hanya halaman kerangka JavaScript (pola riwayat).')),
            'Belum memperhitungkan kuota --max-pages/--max-requests, kegagalan jaringan, dan jeda antar permintaan.',
        ];
    }

    /**
     * Rata-rata konstanta dari daftar run satu site (atau global).
     *
     * @param list<array<string, mixed>> $daftar
     *
     * @return array<string, float>
     */
    private function average(array $daftar): array
    {
        if ($daftar === []) {
            return [];
        }

        $kunci = [
            'detik_unduh', 'detik_parse', 'detik_embed', 'detik_render',
            'vektor_per_url', 'dokumen_per_url', 'porsi_render', 'halaman_per_run',
        ];
        $hasil = [];

        foreach ($kunci as $nama) {
            $nilai = array_values(array_filter(
                array_map(static fn (array $row): float => (float) ($row[$nama] ?? 0.0), $daftar),
                static fn (float $v): bool => $v > 0
            ));

            $hasil[$nama] = $nilai === [] ? 0.0 : array_sum($nilai) / count($nilai);
        }

        return $hasil;
    }

    /**
     * Angka satu run dari log-nya (null bila log tidak punya blok ringkasan).
     *
     * @return array<string, mixed>|null
     */
    private function runMetrics(string $file): ?array
    {
        $isi = (string) @file_get_contents($file);

        if ($isi === '' || !str_contains($isi, 'RINGKASAN')) {
            return null;
        }

        $nilai = static function (string $label) use ($isi): float {
            return preg_match('/' . $label . '\.+\s*:\s*"?([0-9.]+)/', $isi, $c) === 1 ? (float) $c[1] : 0.0;
        };

        $durasi = static function (string $label) use ($isi): float {
            return preg_match('/' . $label . '\.+\s*:\s*"[^"(]*\(([0-9.]+)s\)"/', $isi, $c) === 1 ? (float) $c[1] : 0.0;
        };

        $totalDurasi = $durasi('Durasi total');
        $urlUnduh = $nilai('URL diunduh OK');
        $vektor = $nilai('Vektor berhasil');
        $dokumen = $nilai('Dokumen tersimpan');

        if ($totalDurasi <= 0) {
            return null;
        }

        [$parseTotal, $parseJumlah] = self::sumStage($isi, '[PARSE_AFTER]', null);
        [$embedTotal, $embedVektor] = self::sumStage($isi, '[EMBED_BATCH]', 'vektor');
        [$renderTotal, $renderJumlah] = self::sumStage($isi, '[RENDER_AFTER]', null);

        $halaman = max(1.0, $urlUnduh);
        $lainnya = max(0.0, $totalDurasi - $parseTotal - $embedTotal - $renderTotal);

        return [
            'site' => self::siteTerbanyak($isi),
            'detik_unduh' => $lainnya / $halaman,
            'detik_parse' => $parseJumlah > 0 ? $parseTotal / $parseJumlah : 0.0,
            'detik_embed' => $embedVektor > 0 ? $embedTotal / $embedVektor : 0.0,
            'detik_render' => $renderJumlah > 0 ? $renderTotal / $renderJumlah : 0.0,
            'vektor_per_url' => $vektor / $halaman,
            'dokumen_per_url' => $dokumen / $halaman,
            'porsi_render' => $renderJumlah / $halaman,
            'halaman_per_run' => $urlUnduh,
        ];
    }

    /**
     * Jumlahkan durasi satu tahap pada log.
     *
     * @return array{0: float, 1: float} [total detik, jumlah satuan]
     */
    private static function sumStage(string $isi, string $tahap, ?string $jumlahField): array
    {
        $total = 0.0;
        $satuan = 0.0;

        foreach (explode("\n", $isi) as $line) {
            if (!str_contains($line, $tahap) || preg_match('/durasi=([0-9.]+)s/', $line, $c) !== 1) {
                continue;
            }

            $total += (float) $c[1];

            if ($jumlahField !== null && preg_match('/' . $jumlahField . '=(\d+)/', $line, $n) === 1) {
                $satuan += (float) $n[1];
            } else {
                $satuan += 1.0;
            }
        }

        return [$total, $satuan];
    }

    /**
     * Site yang paling sering muncul pada baris log (untuk mengelompokkan run).
     */
    private static function siteTerbanyak(string $isi): string
    {
        $jumlah = [];

        preg_match_all('/\] \[([a-z0-9][a-z0-9.\-]+)\] \[[A-Z_]+/', $isi, $cocok);

        foreach ($cocok[1] as $site) {
            $jumlah[$site] = ($jumlah[$site] ?? 0) + 1;
        }

        if ($jumlah === []) {
            return 'umum';
        }

        arsort($jumlah);

        return (string) array_key_first($jumlah);
    }
}
