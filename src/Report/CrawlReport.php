<?php

declare(strict_types=1);

namespace App\Report;

use App\Crawl\SiteRepository;
use App\Support\Text;

/**
 * Agregasi hasil crawl untuk halaman Report (public/report.php).
 *
 * Sumber data (berkas yang sudah ada, jadi laporan tetap akurat walau crawl
 * sedang berjalan):
 *
 *   storage/vectors/<site>/<run>.manifest.json  ringkasan run: tanggal dibuat,
 *                                              dokumen, vektor, berkas vektor
 *   storage/vectors/<site>/<run>.jsonl         1 baris = 1 chunk + vektor
 *                                              (untuk run lama yang manifest-nya
 *                                              belum memuat "chunk")
 *   logs/runs/<run>.txt                        DOC_AFTER per dokumen (muncul
 *                                              begitu dokumen selesai) sehingga
 *                                              job yang berjalan bisa dipantau
 */
final class CrawlReport
{
    /** @param array<string, mixed> $paths bagian "paths" pada config/app.php */
    public function __construct(private array $paths)
    {
    }

    public function vectorsDir(): string
    {
        return rtrim((string) ($this->paths['vectors'] ?? base_path('storage/vectors')), '/\\');
    }

    /**
     * Daftar manifest (satu manifest = satu run + satu site).
     *
     * @return list<array<string, mixed>>
     */
    public function manifests(): array
    {
        $hasil = [];
        $pola = $this->vectorsDir() . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*.manifest.json';

        foreach (glob($pola) ?: [] as $file) {
            $json = json_decode((string) @file_get_contents($file), true);

            if (!is_array($json)) {
                continue;
            }

            $vektorFile = $this->absolute((string) ($json['file_vektor'] ?? ''));
            $json['file_manifest'] = str_replace('\\', '/', $file);
            $json['file_vektor_abs'] = $vektorFile;
            $json['tanggal'] = substr((string) ($json['dibuat'] ?? ''), 0, 10);
            $json['run_id'] = (string) ($json['run_id'] ?? '');
            $json['site_id'] = (string) ($json['site_id'] ?? basename(dirname($file)));
            $json['dokumen'] = (int) ($json['dokumen'] ?? 0);
            $json['vektor'] = (int) ($json['vektor'] ?? 0);
            // Manifest run lama belum memuat "chunk": jumlah baris .jsonl
            // (1 baris = 1 chunk tervektor) dipakai supaya laporan tetap lengkap.
            $json['chunk'] = (int) ($json['chunk'] ?? self::countLines($vektorFile));
            $hasil[] = $json;
        }

        usort($hasil, static fn (array $a, array $b): int => strcmp((string) $b['dibuat'], (string) $a['dibuat']));

        return $hasil;
    }

    /**
     * Ringkasan per TANGGAL + SITE untuk tabel laporan.
     *
     * @return array{baris: list<array<string, mixed>>, total: array<string, int>}
     */
    public function summary(?string $dari = null, ?string $sampai = null, string $siteId = ''): array
    {
        $agregat = [];
        $punyaManifest = [];

        foreach ($this->manifests() as $manifest) {
            // Penanda "site|run" supaya run yang sudah dilaporkan manifest tidak
            // dihitung dua kali oleh cadangan log/.jsonl di bawah.
            $punyaManifest[(string) $manifest['site_id'] . '|' . (string) $manifest['run_id']] = true;

            // Manifest lama yang tidak memuat run_id: dicocokkan per tanggal
            // supaya run yang berjalan tidak dihitung dua kali.
            if ((string) $manifest['run_id'] === '') {
                $punyaManifest['TANGGAL|' . (string) $manifest['site_id'] . '|' . (string) $manifest['tanggal']] = true;
            }

            $tanggal = (string) $manifest['tanggal'];

            if ($dari !== null && $dari !== '' && $tanggal < $dari) {
                continue;
            }

            if ($sampai !== null && $sampai !== '' && $tanggal > $sampai) {
                continue;
            }

            if ($siteId !== '' && $siteId !== (string) $manifest['site_id']) {
                continue;
            }

            $kunci = $tanggal . '|' . $manifest['site_id'];

            if (!isset($agregat[$kunci])) {
                $agregat[$kunci] = [
                    'tanggal' => $tanggal,
                    'site_id' => (string) $manifest['site_id'],
                    'site_name' => (string) ($manifest['site_name'] ?? $manifest['site_id']),
                    'run' => 0,
                    'dokumen' => 0,
                    'chunk' => 0,
                    'vektor' => 0,
                    'push_terkirim' => 0,
                    'push_gagal' => 0,
                    'runs' => [],
                ];
            }

            $push = is_array($manifest['qdrant_push'] ?? null) ? $manifest['qdrant_push'] : [];

            $agregat[$kunci]['run']++;
            $agregat[$kunci]['dokumen'] += (int) $manifest['dokumen'];
            $agregat[$kunci]['chunk'] += (int) $manifest['chunk'];
            $agregat[$kunci]['vektor'] += (int) $manifest['vektor'];
            $agregat[$kunci]['push_terkirim'] += (int) ($push['point_terkirim'] ?? 0);
            $agregat[$kunci]['push_gagal'] += (int) ($push['point_gagal'] ?? 0);
            $agregat[$kunci]['runs'][] = [
                'run_id' => (string) $manifest['run_id'],
                'dibuat' => (string) ($manifest['dibuat'] ?? ''),
                'dokumen' => (int) $manifest['dokumen'],
                'chunk' => (int) $manifest['chunk'],
                'vektor' => (int) $manifest['vektor'],
                'push' => (string) ($push['status'] ?? '-'),
                'berkas_vektor' => (string) ($manifest['file_vektor'] ?? ''),
                'aktif' => $this->runAktif((string) $manifest['run_id']),
            ];
        }

        // Cadangan bila manifest belum/tidak tertulis (mis. job yang masih
        // berjalan): angka diambil dari log run (dokumen + chunk) dan berkas
        // .jsonl (vektor) sehingga report harian tetap terisi apa adanya.
        $runBerkas = $this->runDariBerkas($dari, $sampai);
        $namaSite = $runBerkas === [] ? [] : $this->siteOptions();

        foreach ($runBerkas as $runId) {
            $hasilRun = $this->perSiteRun($runId);
            $tanggal = self::tanggalRunId($runId);

            foreach ($hasilRun['baris'] as $row) {
                $site = (string) $row['site'];

                if (
                    isset($punyaManifest[$site . '|' . $runId])
                    || isset($punyaManifest['TANGGAL|' . $site . '|' . $tanggal])
                ) {
                    continue; // sudah dilaporkan manifest
                }

                if ($siteId !== '' && $siteId !== $site) {
                    continue;
                }

                if ($dari !== null && $dari !== '' && $tanggal < $dari) {
                    continue;
                }

                if ($sampai !== null && $sampai !== '' && $tanggal > $sampai) {
                    continue;
                }

                $kunci = $tanggal . '|' . $site;
                $namaLengkap = (string) ($namaSite[$site] ?? '');
                $host = (string) ($row['host'] ?? '');

                if (!isset($agregat[$kunci])) {
                    $agregat[$kunci] = [
                        'tanggal' => $tanggal,
                        'site_id' => $site,
                        // Nama dari config/sites.json, cadangan host dokumen
                        // (mis. site hasil crawl lintas domain yang belum terdaftar).
                        'site_name' => $namaLengkap !== '' ? $namaLengkap : ($host !== '' ? $host : $site),
                        'run' => 0,
                        'dokumen' => 0,
                        'chunk' => 0,
                        'vektor' => 0,
                        'push_terkirim' => 0,
                        'push_gagal' => 0,
                        'runs' => [],
                    ];
                }

                $agregat[$kunci]['run']++;
                $agregat[$kunci]['dokumen'] += (int) $row['dokumen'];
                $agregat[$kunci]['chunk'] += (int) $row['chunk'];
                $agregat[$kunci]['vektor'] += (int) $row['vektor'];
                $agregat[$kunci]['runs'][] = [
                    'run_id' => $runId,
                    'dibuat' => $tanggal . ' ' . self::jamRunId($runId),
                    'dokumen' => (int) $row['dokumen'],
                    'chunk' => (int) $row['chunk'],
                    'vektor' => (int) $row['vektor'],
                    'push' => 'tanpa manifest',
                    'berkas_vektor' => '',
                    'aktif' => !$hasilRun['selesai'],
                    'tanpa_manifest' => true,
                ];
            }
        }

        $baris = array_values($agregat);
        usort($baris, static fn (array $a, array $b): int => [$b['tanggal'], $b['site_id']] <=> [$a['tanggal'], $a['site_id']]);

        $total = ['dokumen' => 0, 'chunk' => 0, 'vektor' => 0, 'run' => 0, 'push_terkirim' => 0];

        foreach ($baris as $row) {
            $total['dokumen'] += (int) $row['dokumen'];
            $total['chunk'] += (int) $row['chunk'];
            $total['vektor'] += (int) $row['vektor'];
            $total['run'] += (int) $row['run'];
            $total['push_terkirim'] += (int) $row['push_terkirim'];
        }

        return ['baris' => $baris, 'total' => $total];
    }

    /**
     * Dokumen yang SUDAH diproses pada satu run (dibaca dari log run sehingga
     * bisa dipakai memantau job yang sedang berjalan).
     *
     * @return list<array<string, mixed>>
     */
    public function documents(string $runId): array
    {
        $log = $this->runLogPath($runId);

        if ($log === null) {
            return [];
        }

        $hasil = [];
        $handle = @fopen($log, 'rb');

        if ($handle === false) {
            return [];
        }

        while (($line = fgets($handle)) !== false) {
            if (strpos($line, '[DOC_AFTER]') === false) {
                continue;
            }

            $dokumen = self::parseDocAfter($line);

            if ($dokumen !== null) {
                $hasil[] = $dokumen;
            }
        }

        fclose($handle);

        return $hasil;
    }

    /**
     * Progres run yang SEDANG berjalan: baris .jsonl bertambah terus sehingga
     * jumlah chunk tervektor bisa dipantau langsung.
     *
     * @return array{chunk_vektor: int, dokumen_log: int, selesai: bool, log: string, aktif: bool}
     */
    public function live(string $runId): array
    {
        $chunkVektor = 0;
        $pola = $this->vectorsDir() . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . $runId . '.jsonl';

        foreach (glob($pola) ?: [] as $file) {
            $chunkVektor += self::countLines($file);
        }

        $log = $this->runLogPath($runId);
        $selesai = false;

        if ($log !== null) {
            $selesai = str_contains((string) @file_get_contents($log), '[RUN_END]');
        }

        return [
            'chunk_vektor' => $chunkVektor,
            'dokumen_log' => count($this->documents($runId)),
            'selesai' => $selesai,
            'log' => $log ?? '',
            'aktif' => !$selesai && $this->runAktif($runId),
        ];
    }

    /**
     * Ringkasan per WEBSITE untuk satu run: Dokumen / Chunk / Vektor.
     *
     * Dipakai untuk laporan saat run masih BERJALAN:
     *   - Dokumen & Chunk dari baris [DOC_AFTER] status=SUKSES pada log run
     *     (dokumen itu sudah berhasil di-parse & di-embed);
     *   - Vektor dari jumlah baris storage/vectors/<site>/<run>.jsonl
     *     (1 baris = 1 chunk tervektor) sehingga angkanya bertambah nyata.
     *
     * @return array{baris: list<array<string, mixed>>, total: array<string, int>, selesai: bool, log: string}
     */
    public function perSiteRun(string $runId): array
    {
        $perSite = [];
        $total = ['dokumen' => 0, 'chunk' => 0, 'vektor' => 0];
        $selesai = false;
        $log = $this->runLogPath($runId);

        if ($log !== null) {
            $handle = @fopen($log, 'rb');

            if ($handle !== false) {
                while (($line = fgets($handle)) !== false) {
                    if (str_contains($line, '[RUN_END]')) {
                        $selesai = true;
                    }

                    if (!str_contains($line, '[DOC_AFTER]') || !str_contains($line, 'status=SUKSES')) {
                        continue;
                    }

                    if (preg_match('/^\[[^\]]+\] \[[^\]]+\] \[([^\]]+)\] \[DOC_AFTER\]/', $line, $cocok) !== 1) {
                        continue;
                    }

                    $site = (string) $cocok[1];
                    $chunk = preg_match('/\bchunk=(\d+)/', $line, $m) === 1 ? (int) $m[1] : 0;
                    $vektor = preg_match('/\bvektor=(\d+)/', $line, $m2) === 1 ? (int) $m2[1] : 0;

                    // Host dipakai sebagai nama tampilan bila site belum terdaftar
                    // di config/sites.json (mis. hasil crawl lintas domain).
                    if (!isset($perSite[$site]['host']) && preg_match('/\burl="?([^"\s]+)"?/', $line, $mu) === 1) {
                        $perSite[$site]['host'] = (string) (parse_url($mu[1], PHP_URL_HOST) ?? '');
                    }

                    $perSite[$site]['dokumen'] = (int) ($perSite[$site]['dokumen'] ?? 0) + 1;
                    $perSite[$site]['chunk'] = (int) ($perSite[$site]['chunk'] ?? 0) + $chunk;
                    $perSite[$site]['vektor_log'] = (int) ($perSite[$site]['vektor_log'] ?? 0) + $vektor;
                }

                fclose($handle);
            }
        }

        // Jumlah baris .jsonl per site = chunk yang BENAR-BENAR tervektor.
        $pola = $this->vectorsDir() . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . $runId . '.jsonl';

        foreach (glob($pola) ?: [] as $file) {
            $site = basename(dirname($file));
            $perSite[$site]['vektor'] = self::countLines($file);

            if (!isset($perSite[$site]['dokumen'])) {
                $perSite[$site]['dokumen'] = 0;
                $perSite[$site]['chunk'] = 0;
            }

            if (!isset($perSite[$site]['host'])) {
                $perSite[$site]['host'] = self::hostDariJsonl($file);
            }
        }

        $baris = [];

        foreach ($perSite as $site => $angka) {
            $vektor = (int) ($angka['vektor'] ?? ($angka['vektor_log'] ?? 0));
            $chunk = (int) ($angka['chunk'] ?? 0);
            $dokumen = (int) ($angka['dokumen'] ?? 0);

            $baris[] = [
                'site' => (string) $site,
                'host' => (string) ($angka['host'] ?? ''),
                'dokumen' => $dokumen,
                'chunk' => $chunk > 0 ? $chunk : $vektor,
                'vektor' => $vektor,
            ];

            $total['dokumen'] += $dokumen;
            $total['chunk'] += $chunk > 0 ? $chunk : $vektor;
            $total['vektor'] += $vektor;
        }

        usort($baris, static fn (array $a, array $b): int => strcmp((string) $a['site'], (string) $b['site']));

        return ['baris' => $baris, 'total' => $total, 'selesai' => $selesai, 'log' => $log ?? ''];
    }

    /**
     * Run id TERBARU yang punya berkas vektor (default untuk halaman Report).
     *
     * Run id berformat RUN-YYYYMMDD-HHMMSS sehingga urutan leksikografis sama
     * dengan urutan waktu — tidak terpengaruh berkas run lama yang masih
     * ditulis proses yang tertinggal.
     */
    public function latestRunId(): string
    {
        $runIds = [];

        $pola = $this->vectorsDir() . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*.jsonl';

        foreach (glob($pola) ?: [] as $file) {
            $runIds[basename($file, '.jsonl')] = true;
        }

        if ($runIds === []) {
            return '';
        }

        $kunci = array_keys($runIds);
        sort($kunci);

        return (string) end($kunci);
    }

    /**
     * Daftar id site untuk filter (dari manifest + config/sites.json).
     *
     * @return array<string, string> id => nama
     */
    public function siteOptions(): array
    {
        $opsi = [];

        foreach ($this->manifests() as $manifest) {
            $id = (string) $manifest['site_id'];
            $opsi[$id] = (string) ($manifest['site_name'] ?? $id);
        }

        try {
            foreach ((new SiteRepository())->all() as $site) {
                if (!isset($opsi[$site->id])) {
                    $opsi[$site->id] = $site->name;
                }
            }
        } catch (\Throwable) {
            // config/sites.json bisa saja belum ada: daftar dari manifest cukup.
        }

        ksort($opsi);

        return $opsi;
    }

    /**
     * Hitung baris berkas besar tanpa memuat seluruh isi ke memori.
     *
     * Hasil disimpan di storage/cache/line-counts.json dan dipakai ulang hanya
     * bila ukuran + waktu ubah berkas masih sama, sehingga berkas run yang
     * sedang berjalan tetap dihitung ulang apa adanya.
     */
    public static function countLines(string $file): int
    {
        if ($file === '' || !is_file($file)) {
            return 0;
        }

        $kunci = str_replace('\\', '/', $file);
        $ukuran = (int) @filesize($file);
        $ubah = (int) @filemtime($file);
        $cache = self::cacheBaca();

        if (
            isset($cache[$kunci])
            && (int) ($cache[$kunci]['ukuran'] ?? -1) === $ukuran
            && (int) ($cache[$kunci]['ubah'] ?? -1) === $ubah
        ) {
            return (int) ($cache[$kunci]['baris'] ?? 0);
        }

        $baris = self::hitungBaris($file);

        $cache[$kunci] = ['ukuran' => $ukuran, 'ubah' => $ubah, 'baris' => $baris];
        self::cacheTulis($cache);

        return $baris;
    }

    private static function cachePath(): string
    {
        return base_path('storage/cache/line-counts.json');
    }

    /** @return array<string, array<string, int>> */
    private static function cacheBaca(): array
    {
        $path = self::cachePath();

        if (!is_file($path)) {
            return [];
        }

        $json = json_decode((string) @file_get_contents($path), true);

        return is_array($json) ? $json : [];
    }

    /** @param array<string, array<string, int>> $cache */
    private static function cacheTulis(array $cache): void
    {
        // Simpan hanya 200 entri terakhir supaya berkas cache tidak membengkak.
        if (count($cache) > 200) {
            $cache = array_slice($cache, -200, null, true);
        }

        $dir = dirname(self::cachePath());

        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return;
        }

        @file_put_contents(self::cachePath(), (string) json_encode($cache), LOCK_EX);
    }

    private static function hitungBaris(string $file): int
    {
        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return 0;
        }

        $lines = 0;

        while (!feof($handle)) {
            $buffer = fread($handle, 1_048_576);

            if ($buffer === false || $buffer === '') {
                break;
            }

            $lines += substr_count($buffer, "\n");
        }

        fclose($handle);

        return $lines;
    }

    /** Host dokumen pertama pada berkas .jsonl ('' bila tidak terbaca). */
    private static function hostDariJsonl(string $file): string
    {
        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return '';
        }

        $awal = (string) fread($handle, 8192);
        fclose($handle);

        if (preg_match('/"url":"([^"]+)"/', $awal, $cocok) !== 1) {
            return '';
        }

        $url = str_replace('\\/', '/', $cocok[1]);

        return (string) (parse_url($url, PHP_URL_HOST) ?? '');
    }

    private function runsDir(): string
    {
        return rtrim((string) ($this->paths['runs'] ?? base_path('logs/runs')), '/\\');
    }

    /**
     * Run id yang punya jejak berkas (log run atau berkas vektor) pada rentang
     * tanggal tertentu. Dipakai sebagai cadangan saat manifest belum tertulis.
     *
     * @return list<string>
     */
    private function runDariBerkas(?string $dari, ?string $sampai): array
    {
        $kandidat = [];

        $pola = [
            $this->vectorsDir() . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*.jsonl',
            $this->runsDir() . DIRECTORY_SEPARATOR . '*.txt',
        ];

        foreach ($pola as $glob) {
            foreach (glob($glob) ?: [] as $file) {
                $runId = (string) preg_replace('/\.(jsonl|txt)$/', '', basename($file));

                if (preg_match('/^RUN-\d{8}-\d{6}$/', $runId) === 1) {
                    $kandidat[$runId] = true;
                }
            }
        }

        $dalamRentang = [];

        foreach (array_keys($kandidat) as $runId) {
            $tanggal = self::tanggalRunId($runId);

            if ($tanggal === '') {
                continue;
            }

            if ($dari !== null && $dari !== '' && $tanggal < $dari) {
                continue;
            }

            if ($sampai !== null && $sampai !== '' && $tanggal > $sampai) {
                continue;
            }

            $dalamRentang[] = (string) $runId;
        }

        sort($dalamRentang);

        return $dalamRentang;
    }

    /** RUN-YYYYMMDD-HHMMSS -> YYYY-MM-DD ('' bila format tidak dikenal). */
    private static function tanggalRunId(string $runId): string
    {
        if (preg_match('/^RUN-(\d{4})(\d{2})(\d{2})-\d{6}$/', $runId, $cocok) !== 1) {
            return '';
        }

        return $cocok[1] . '-' . $cocok[2] . '-' . $cocok[3];
    }

    /** RUN-YYYYMMDD-HHMMSS -> HH:MM:SS ('' bila format tidak dikenal). */
    private static function jamRunId(string $runId): string
    {
        if (preg_match('/^RUN-\d{8}-(\d{2})(\d{2})(\d{2})$/', $runId, $cocok) !== 1) {
            return '';
        }

        return $cocok[1] . ':' . $cocok[2] . ':' . $cocok[3];
    }

    private function runLogPath(string $runId): ?string
    {
        if ($runId === '') {
            return null;
        }

        $path = $this->runsDir() . DIRECTORY_SEPARATOR . $runId . '.txt';

        return is_file($path) ? $path : null;
    }

    private function runAktif(string $runId): bool
    {
        $log = $this->runLogPath($runId);

        if ($log === null) {
            return false;
        }

        // Log yang masih bertambah beberapa menit terakhir = run masih berjalan.
        return (time() - (int) @filemtime($log)) < 180;
    }

    /**
     * Satu baris [DOC_AFTER] -> data dokumen.
     *
     * @return array<string, mixed>|null
     */
    private static function parseDocAfter(string $line): ?array
    {
        if (preg_match('/\[DOC_AFTER\] status=(\S+)(?:\s+url=(\S*))?/', $line, $cocok) !== 1) {
            return null;
        }

        $nilai = static function (string $kunci) use ($line): string {
            if (preg_match('/\b' . $kunci . '=("([^"]*)"|\S+)/', $line, $c) !== 1) {
                return '';
            }

            $kutip = $c[2] ?? '';

            return trim((string) ($kutip !== '' ? $kutip : $c[1]), '"');
        };

        return [
            'status' => (string) $cocok[1],
            'url' => (string) ($cocok[2] ?? ''),
            'document_id' => $nilai('document_id'),
            'judul' => $nilai('judul') !== '' ? $nilai('judul') : $nilai('document_id'),
            'mode_parse' => $nilai('mode_parse'),
            'chunk' => (int) $nilai('chunk'),
            'vektor' => (int) $nilai('vektor'),
            'tahap' => $nilai('tahap'),
        ];
    }

    private function absolute(string $relative): string
    {
        $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($relative));

        if ($relative === '') {
            return '';
        }

        if (preg_match('#^[A-Za-z]:\\\\#', $relative) === 1 || str_starts_with($relative, DIRECTORY_SEPARATOR)) {
            return $relative;
        }

        return base_path($relative);
    }

    /**
     * Path relatif ringkas untuk tabel.
     */
    public static function shortPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $base = str_replace('\\', '/', base_path()) . '/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : Text::oneLine($path, 80);
    }
}
