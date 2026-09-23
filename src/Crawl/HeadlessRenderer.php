<?php

declare(strict_types=1);

namespace App\Crawl;

/**
 * Perender halaman berbasis JavaScript memakai browser headless yang sudah ada
 * di mesin (Chrome / Edge / Chromium) lewat opsi `--dump-dom`.
 *
 * Situs SPA (Next.js/Nuxt/Vue dsb.) mengirim HTML kerangka saat diunduh tanpa
 * menjalankan JavaScript: yang terlihat hanya menu, tagline, dan footer. Dengan
 * menjalankan browser headless lebih dulu, DOM yang dihasilkan sudah memuat isi
 * halaman sebenarnya sehingga crawler tidak butuh aturan API per situs.
 *
 * Pemakaian:
 *
 *   $renderer = new HeadlessRenderer($config['crawl'] ?? []);
 *   if ($renderer->available() && HeadlessRenderer::looksJsOnly($html)) {
 *       $hasil = $renderer->render($url, $site->jsRender);   // null bila gagal
 *   }
 *
 * Semua kegagalan bersifat lunak: pemanggil harus bisa melanjutkan dengan HTML
 * hasil unduhan biasa. Alasan kegagalan terakhir dibaca lewat lastError().
 */
final class HeadlessRenderer
{
    /** Penanda HTML kerangka aplikasi JavaScript (SPA) pada halaman target. */
    private const MARKERS = [
        'self.__next_f',
        '__NEXT_DATA__',
        'id="__next"',
        '__NUXT__',
        'id="__nuxt"',
        'data-reactroot',
        'id="root"',
        'id="app"',
        'ng-version',
        'data-server-rendered',
        'id="___gatsby"',
    ];

    /** Nama biner browser pada sistem mirip-Unix (dicari di PATH). */
    private const UNIX_NAMES = [
        'google-chrome',
        'google-chrome-stable',
        'chromium',
        'chromium-browser',
        'microsoft-edge',
        'microsoft-edge-stable',
        'brave-browser',
    ];

    /**
     * UA bawaan: UA browser sungguhan supaya situs tidak menolak
     * "HeadlessChrome" (beberapa situs memblokirnya).
     */
    public const DEFAULT_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private ?string $bin = null;

    private bool $detected = false;

    private string $error = '';

    /**
     * Berkas log jaringan (NetLog) hasil render TERAKHIR: dipakai crawler untuk
     * menemukan berkas/API yang dipanggil halaman (lihat netLogPath()).
     */
    private ?string $netLog = null;

    /**
     * Sapuan sisa berkas sementara hanya dijalankan sekali per proses
     * (lihat sweepTempOnce()) supaya tidak membebani setiap render.
     */
    private static bool $tempSwept = false;

    /** @var array<string, mixed> setelan crawl (js_render, browser_bin, ...) */
    private array $config;

    /**
     * @param array<string, mixed> $config isi config/app.php bagian "crawl"
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function __destruct()
    {
        $this->forgetNetLog();
    }

    /**
     * Path log jaringan (NetLog) hasil render terakhir, atau null bila render
     * terakhir tidak memakai NetLog / gagal. Pemanggil (Pipeline) membacanya
     * lewat App\Crawl\DocumentHarvest, lalu file dibuang oleh forgetNetLog().
     */
    public function netLogPath(): ?string
    {
        return $this->netLog !== null && is_file($this->netLog) ? $this->netLog : null;
    }

    /**
     * Buang berkas NetLog hasil render terakhir (dipanggil setelah dibaca atau
     * saat render berikutnya dimulai supaya berkas sementara tidak menumpuk).
     */
    public function forgetNetLog(): void
    {
        if ($this->netLog !== null && is_file($this->netLog)) {
            @unlink($this->netLog);
        }

        $this->netLog = null;
    }

    /**
     * Setelan bawaan mode render (dipakai bila site tidak menimpanya).
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'mode' => 'auto',
            'wait_ms' => 6000,
            'timeout_ms' => 25000,
            'bin' => '',
            'flags' => '',
            'user_agent' => self::DEFAULT_UA,
            'min_text' => 2000,
            'ratio' => 0.03,
            // Catat log jaringan Chrome selama render (--log-net-log) supaya
            // permintaan JS/XHR halaman bisa dibaca crawler: dari sana tautan
            // berkas yang TIDAK muncul di HTML/DOM (mis. daftar PDF dari API)
            // ditemukan otomatis. Lihat App\Crawl\DocumentHarvest.
            'net_log' => true,
        ];
    }

    /**
     * Mode render dari config/app.php: off | auto | always.
     */
    public function mode(): string
    {
        return self::modeValue($this->config['js_render'] ?? null) ?? 'auto';
    }

    /**
     * Normalisasi nilai mode render dari env/config/entri site.
     *
     * @return string|null null bila nilai tidak dikenali
     */
    public static function modeValue(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'always' : 'off';
        }

        if (!is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            'off', 'no', 'false', '0', 'tidak', 'mati' => 'off',
            'auto', 'otomatis' => 'auto',
            'always', 'on', 'true', '1', 'ya', 'selalu', 'paksa' => 'always',
            default => null,
        };
    }

    /**
     * Apakah browser headless tersedia di mesin ini (hasil deteksi di-cache).
     */
    public function available(): bool
    {
        return $this->bin() !== null;
    }

    /**
     * Path biner browser headless: dari setelan "browser_bin" atau deteksi
     * otomatis (Chrome/Edge/Chromium).
     */
    public function bin(): ?string
    {
        if ($this->detected) {
            return $this->bin;
        }

        $this->detected = true;
        $diminta = trim((string) ($this->config['browser_bin'] ?? ''));

        if ($diminta !== '' && !in_array(strtolower($diminta), ['none', 'off', 'false', '0', 'tidak'], true)) {
            if (self::isRunnable($diminta)) {
                $this->bin = $diminta;

                return $this->bin;
            }

            // Path eksplisit yang salah tidak jatuh ke deteksi otomatis supaya
            // salah tulis setelan langsung terlihat pada log.
            $this->error = 'browser_bin tidak ditemukan atau tidak bisa dijalankan: ' . $diminta;

            return null;
        }

        foreach (self::candidates() as $kandidat) {
            if (self::isRunnable($kandidat)) {
                $this->bin = $kandidat;

                return $this->bin;
            }
        }

        $this->error = 'browser headless (Chrome/Edge/Chromium) tidak ditemukan; '
            . 'pasang salah satunya atau set CRAWLER_BROWSER=<path biner>';

        return null;
    }

    /**
     * Alasan kegagalan terakhir (kosong bila belum ada kegagalan).
     */
    public function lastError(): string
    {
        return $this->error;
    }

    /**
     * Render satu URL dan kembalikan DOM hasil render.
     *
     * @param array<string, mixed> $settings setelan per site (mode render)
     *
     * @return array{html: string, seconds: float, bin: string, bytes: int}|null
     *                                                                        null bila render gagal (lihat lastError())
     */
    public function render(string $url, array $settings = []): ?array
    {
        // Sisa berkas/folder sementara render dari proses yang berhenti mendadak
        // (galat fatal / dihentikan paksa) dibersihkan sekali per proses supaya
        // folder temp sistem tidak menumpuk profil browser.
        self::sweepTempOnce($this->config);

        $setelan = $settings + self::defaults() + $this->config;
        $bin = trim((string) ($setelan['bin'] ?? ''));

        if ($bin === '' || !self::isRunnable($bin)) {
            $bin = (string) ($this->bin() ?? '');
        }

        $this->error = '';

        if ($bin === '' || !self::isRunnable($bin)) {
            $this->error = $this->error === '' ? 'browser headless tidak tersedia' : $this->error;

            return null;
        }

        $tunggu = max(0, (int) ($setelan['wait_ms'] ?? 6000));
        $timeout = max(1000, (int) ($setelan['timeout_ms'] ?? 25000));
        $ua = trim((string) ($setelan['user_agent'] ?? ''));
        $flags = self::extraFlags((string) ($setelan['flags'] ?? ''));
        $netLog = (bool) ($setelan['net_log'] ?? true);

        // NetLog render sebelumnya tidak dipakai lagi: buang supaya berkas
        // sementara di folder temp tidak menumpuk selama run panjang.
        $this->forgetNetLog();

        // Chrome 112+ memakai --headless=new; biner lama hanya mengerti
        // --headless, jadi percobaan kedua dijalankan bila percobaan pertama
        // tidak menghasilkan DOM sama sekali.
        foreach (['--headless=new', '--headless'] as $index => $mode) {
            $mulai = microtime(true);
            $html = $this->runBrowser($bin, $url, $mode, $tunggu, $timeout, $ua === '' ? self::DEFAULT_UA : $ua, $flags, $netLog);
            $durasi = microtime(true) - $mulai;

            if ($html !== null && trim($html) !== '') {
                return [
                    'html' => $html,
                    'seconds' => round($durasi, 3),
                    'bin' => $bin,
                    'bytes' => strlen($html),
                ];
            }

            if ($index === 0 && $this->error !== '' && stripos($this->error, 'headless') === false) {
                // Kegagalan bukan soal penamaan mode headless: tidak perlu
                // mencoba mode lama (menghemat waktu per halaman).
                break;
            }
        }

        return null;
    }

    /**
     * Jalankan browser satu kali dengan keluaran DOM ke berkas sementara.
     *
     * Berkas dipakai (bukan pipa) supaya keluaran besar tidak mengunci proses;
     * profil browser juga ditaruh di folder sementara dan dihapus setelah selesai.
     *
     * @param list<string> $flags
     */
    private function runBrowser(
        string $bin,
        string $url,
        string $headlessMode,
        int $waitMs,
        int $timeoutMs,
        string $userAgent,
        array $flags,
        bool $netLog = false,
    ): ?string {
        $tanda = substr(sha1($bin . '|' . $url . '|' . microtime(true)), 0, 12);
        $profil = self::tempDir() . DIRECTORY_SEPARATOR . 'crawler-profil-' . $tanda;
        $keluar = self::tempDir() . DIRECTORY_SEPARATOR . 'crawler-dom-' . $tanda . '.html';
        $galat = self::tempDir() . DIRECTORY_SEPARATOR . 'crawler-gal-' . $tanda . '.log';
        $netlog = self::tempDir() . DIRECTORY_SEPARATOR . 'crawler-net-' . $tanda . '.json';

        $args = [
            $bin,
            $headlessMode,
            '--disable-gpu',
            '--no-first-run',
            '--no-default-browser-check',
            '--disable-extensions',
            '--disable-dev-shm-usage',
            '--disable-background-networking',
            '--disable-sync',
            '--hide-scrollbars',
            '--mute-audio',
            '--user-agent=' . $userAgent,
            '--user-data-dir=' . $profil,
            '--virtual-time-budget=' . $waitMs,
        ];

        // Chrome di dalam kontainer/root menolak sandbox-nya sendiri.
        if (PHP_OS_FAMILY !== 'Windows' && function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $args[] = '--no-sandbox';
            $args[] = '--disable-setuid-sandbox';
        }

        foreach ($flags as $flag) {
            $args[] = $flag;
        }

        // Log jaringan Chrome: berisi SEMUA permintaan halaman (aset, XHR/fetch,
        // panggilan API) sehingga crawler bisa menemukan berkas dokumen yang
        // tidak pernah muncul di HTML/DOM (mis. daftar PDF dari API situs).
        // --net-log-capture-mode=IncludeSensitive wajib supaya isi permintaan
        // (header/URL lengkap) ikut tercatat.
        if ($netLog) {
            $args[] = '--log-net-log=' . $netlog;
            $args[] = '--net-log-capture-mode=IncludeSensitive';
        }

        $args[] = '--dump-dom';
        $args[] = $url;

        $proc = @proc_open($args, [
            1 => ['file', $keluar, 'wb'],
            2 => ['file', $galat, 'wb'],
        ], $pipes);

        if (!is_resource($proc)) {
            $this->error = 'proc_open gagal menjalankan ' . $bin;

            return null;
        }

        $mulai = microtime(true);
        $selesai = false;

        while (microtime(true) - $mulai < $timeoutMs / 1000) {
            $status = proc_get_status($proc);

            if ($status === false || !$status['running']) {
                $selesai = true;

                break;
            }

            usleep(100_000);
        }

        if (!$selesai) {
            proc_terminate($proc, 9);
            proc_close($proc);

            // Sebagian browser sudah menulis DOM sebelum digantung; pakai bila ada.
            $html = is_file($keluar) ? (string) file_get_contents($keluar) : '';
            self::cleanup([$keluar, $galat], $profil);

            if (trim($html) === '') {
                // Render gagal: log jaringan tidak berguna.
                self::cleanup([$netlog], $profil);

                $this->error = sprintf('browser headless timeout %dms saat merender URL', $timeoutMs);

                return null;
            }

            $this->keepNetLog($netLog, $netlog);

            return $html;
        }

        proc_close($proc);

        $html = is_file($keluar) ? (string) file_get_contents($keluar) : '';
        $pesan = is_file($galat) ? trim((string) file_get_contents($galat)) : '';

        self::cleanup([$keluar, $galat], $profil);

        if (trim($html) === '') {
            self::cleanup([$netlog], $profil);

            $this->error = 'browser headless tidak menghasilkan DOM'
                . ($pesan === '' ? '' : ': ' . mb_substr(preg_replace('/\s+/u', ' ', $pesan) ?? $pesan, 0, 300));

            return null;
        }

        // Halaman galat bawaan Chrome (mis. sertifikat/dns) tidak pernah dipakai.
        if (str_contains($html, 'ERR_CERT_') || str_contains($html, 'chrome-error://')) {
            self::cleanup([$netlog], $profil);

            $this->error = 'browser headless gagal membuka halaman (sertifikat/koneksi ditolak browser)';

            return null;
        }

        $this->keepNetLog($netLog, $netlog);

        return $html;
    }

    /**
     * Jadikan berkas NetLog hasil render ini sebagai log jaringan terakhir yang
     * bisa dibaca crawler; berkas kosong/gagal langsung dibuang.
     */
    private function keepNetLog(bool $enabled, string $path): void
    {
        if (!$enabled || !is_file($path) || (int) @filesize($path) === 0) {
            if (is_file($path)) {
                @unlink($path);
            }

            return;
        }

        $this->netLog = $path;
    }

    /**
     * Buang bagian yang bukan isi halaman dari DOM hasil render: skrip, gaya
     * tampilan, noscript, template, SVG, dan komentar HTML.
     *
     * DOM hasil render masih memuat seluruh bundel JavaScript + payload data
     * (mis. self.__next_f pada Next.js) sehingga berkasnya bisa ratusan KB;
     * layanan /parse sempat hanya menghasilkan 10 karakter untuk halaman
     * beranda yang DOM-nya 126 KB. Setelah bagian non-isi dibuang, /parse dan
     * parser lokal membaca isi halaman sebagaimana mestinya (berkas simpanan
     * storage/html juga jadi jauh lebih kecil).
     */
    public static function stripNonContent(string $html): string
    {
        $bersih = preg_replace('#<(script|style|noscript|template|svg|link|iframe)\b[^>]*>.*?</\1\s*>#is', ' ', $html)
            ?? $html;
        // Tag pembuka tunggal (mis. <link ...>) tidak punya pasangan penutup.
        $bersih = preg_replace('#<(script|style|noscript|template|svg|link|iframe)\b[^>]*/?>#is', ' ', $bersih) ?? $bersih;
        $bersih = preg_replace('#<!--.*?-->#s', ' ', $bersih) ?? $bersih;

        return trim($bersih);
    }

    /**
     * Apakah HTML yang diunduh hanya kerangka aplikasi JavaScript?
     *
     * Halaman SPA mengirim menu/tagline/footer tanpa isi karena isinya baru
     * dibuat di browser: teks terlihatnya sangat pendek (mis. 1.036 karakter
     * untuk halaman sejarah Kemendagri) sementara berkas HTML-nya puluhan KB.
     * Halaman yang isinya sudah dikirim server tidak pernah dianggap kerangka
     * karena teksnya panjang.
     *
     * @param int   $minText  batas teks terlihat (karakter) yang dianggap cukup
     * @param float $maxRatio batas rasio teks/HTML untuk halaman tanpa penanda SPA
     */
    public static function looksJsOnly(string $html, int $minText = 2000, float $maxRatio = 0.03): bool
    {
        if ($html === '') {
            return false;
        }

        $teks = self::visibleText($html);
        $panjang = mb_strlen($teks);

        if ($panjang >= max(1, $minText)) {
            return false;
        }

        if (self::jsMarkers($html) !== []) {
            return true;
        }

        // Tanpa penanda SPA yang dikenali: hanya dianggap kerangka bila hampir
        // tidak ada teks dibandingkan ukuran berkas (mis. halaman galeri).
        return (mb_strlen($teks) / max(1, strlen($html))) < max(0.0, $maxRatio);
    }

    /**
     * Penanda kerangka SPA yang ditemukan pada HTML (akhir-akhir ini Next.js
     * menulis data halaman pada skrip self.__next_f).
     *
     * @return list<string>
     */
    public static function jsMarkers(string $html): array
    {
        $ditemukan = [];

        foreach (self::MARKERS as $marker) {
            if (str_contains($html, $marker)) {
                $ditemukan[] = $marker;
            }
        }

        if (preg_match('/enable\s+JavaScript|aktifkan\s+JavaScript|JavaScript\s+(?:harus|perlu|wajib)|requires?\s+JavaScript/iu', $html) === 1) {
            $ditemukan[] = 'pesan-javascript';
        }

        return $ditemukan;
    }

    /**
     * Teks yang benar-benar terlihat (tanpa skrip, gaya tampilan, dan tag).
     */
    public static function visibleText(string $html): string
    {
        $bersih = preg_replace('#<(script|style|noscript|template|svg|head)[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $bersih = preg_replace('#<!--.*?-->#s', ' ', $bersih) ?? $bersih;
        $bersih = strip_tags($bersih);
        $bersih = html_entity_decode($bersih, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $bersih) ?? '');
    }

    /**
     * Pisahkan setelan "flags" tambahan (dipisah spasi) menjadi daftar argumen.
     *
     * @return list<string>
     */
    private static function extraFlags(string $flags): array
    {
        $flags = trim($flags);
        if ($flags === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/\s+/', $flags) ?: []),
            static fn (string $flag): bool => $flag !== ''
        ));
    }

    private static function tempDir(): string
    {
        $dir = sys_get_temp_dir();

        return $dir === '' ? '.' : rtrim($dir, '/\\');
    }

    /**
     * Jalankan sapuan sisa berkas sementara sekali per proses, memakai batas
     * umur dari config (crawl.render_temp_hours / CRAWLER_RENDER_TEMP_HOURS).
     *
     * @param array<string, mixed> $config setelan crawl
     */
    private static function sweepTempOnce(array $config): void
    {
        if (self::$tempSwept) {
            return;
        }

        self::$tempSwept = true;
        $jam = max(1, (int) ($config['render_temp_hours'] ?? 6));

        try {
            self::sweepTemp($jam * 3600);
        } catch (\Throwable) {
            // Pembersihan bersifat kebersihan saja: kegagalannya tidak boleh
            // mengganggu render halaman.
        }
    }

    /**
     * Hapus sisa berkas/folder sementara render yang lebih tua dari batas umur.
     *
     * Proses yang berhenti mendadak (galat fatal, dihentikan paksa, mati listrik)
     * tidak sempat menjalankan pembersihan normal sehingga folder profil browser
     * (puluhan MB per render) + DOM/NetLog menumpuk di folder temp sistem.
     * Sapuan ini hanya menyentuh berkas milik crawler ("crawler-*") dan
     * meninggalkan apa pun yang lebih baru dari batas umur supaya render yang
     * SEDANG berjalan (proses lain) tidak terganggu.
     *
     * @param int $maxAgeSeconds umur minimal sebelum boleh dihapus (0 = semua)
     *
     * @return list<string> nama berkas/folder yang dihapus
     */
    public static function sweepTemp(int $maxAgeSeconds = 21600): array
    {
        $dir = self::tempDir();
        $batas = time() - max(0, $maxAgeSeconds);
        $dihapus = [];

        foreach (['crawler-profil-*', 'crawler-dom-*', 'crawler-gal-*', 'crawler-net-*', 'crawler-render-*'] as $pola) {
            foreach (glob($dir . DIRECTORY_SEPARATOR . $pola) ?: [] as $path) {
                $waktu = @filemtime($path);

                if ($waktu === false || $waktu > $batas) {
                    continue;
                }

                if (is_dir($path)) {
                    self::removeDirectory($path);

                    if (!is_dir($path)) {
                        $dihapus[] = basename($path);
                    }

                    continue;
                }

                if (@unlink($path)) {
                    $dihapus[] = basename($path);
                }
            }
        }

        return $dihapus;
    }

    /**
     * @param list<string> $files
     */
    private static function cleanup(array $files, string $profil): void
    {
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        self::removeDirectory($profil);
    }

    /**
     * Hapus folder profil browser secara rekursif (hanya di dalam folder
     * sementara sistem supaya tidak pernah menghapus berkas pengguna).
     */
    private static function removeDirectory(string $dir): void
    {
        $dir = rtrim($dir, '/\\');

        if ($dir === '' || !is_dir($dir) || !str_starts_with($dir, self::tempDir())) {
            return;
        }

        $items = @scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                self::removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    private static function isRunnable(string $path): bool
    {
        return $path !== '' && is_file($path)
            && (PHP_OS_FAMILY === 'Windows' || is_executable($path));
    }

    /**
     * Kandidat lokasi browser headless sesuai sistem operasi.
     *
     * @return list<string>
     */
    private static function candidates(): array
    {
        $candidates = [];

        if (PHP_OS_FAMILY === 'Windows') {
            foreach (['PROGRAMFILES', 'PROGRAMFILES(X86)', 'LOCALAPPDATA'] as $var) {
                $base = getenv($var);
                if (!is_string($base) || trim($base) === '') {
                    continue;
                }

                $candidates[] = $base . '\\Google\\Chrome\\Application\\chrome.exe';
                $candidates[] = $base . '\\Microsoft\\Edge\\Application\\msedge.exe';
                $candidates[] = $base . '\\Chromium\\Application\\chrome.exe';
            }

            return $candidates;
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            $candidates[] = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
            $candidates[] = '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge';
            $candidates[] = '/Applications/Chromium.app/Contents/MacOS/Chromium';
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            $dir = trim($dir);
            if ($dir === '') {
                continue;
            }

            foreach (self::UNIX_NAMES as $name) {
                $candidates[] = rtrim($dir, '/') . '/' . $name;
            }
        }

        return $candidates;
    }
}

