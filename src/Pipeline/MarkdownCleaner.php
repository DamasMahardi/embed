<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\Support\Text;

/**
 * Pembersih markdown hasil /parse sebelum dipecah menjadi chunk.
 *
 * Layanan /parse mengembalikan markdown apa adanya: menu navigasi, label
 * antarmuka, ornament/ikon kosong, dan footer yang selalu sama di setiap
 * halaman. Bila ikut dikirim ke /embed, teks semacam itu (1) mengaburkan isi
 * halaman saat pencarian vektor karena vektor "isi halaman" menjadi mirip
 * dengan vektor "daftar menu", dan (2) menambah jumlah chunk/point di Qdrant
 * tanpa nilai tambah.
 *
 * Aturan pembersihan sengaja deterministik (tanpa model bahasa) supaya
 * checksum dokumen tetap stabil: input yang sama selalu menghasilkan markdown
 * yang sama, sehingga re-crawl tidak membuat point duplikat.
 *
 * Urutan pembersihan:
 *   1. komentar HTML ("<!-- image -->") dan gambar inline dibuang;
 *   2. destinasi tautan sisa konversi service diperbaiki
 *      ("(\profil\sejarah)" -> "(/profil/sejarah)", "(\\)" -> "(/)");
 *   3. label tautan dirapikan ("Informasi Publik Informas..." -> "Informasi
 *      Publik", "Beranda Beranda" -> "Beranda") dan tautan tanpa label dibuang;
 *   4. baris label antarmuka/footer (menu, ornament, "Total Pengunjung",
 *      "Bulan ini", hak cipta) serta baris pemisah murni ("---", "--") dibuang;
 *      termasuk footer yang tidak selalu di awal baris (versi aplikasi,
 *      "All rights reserved"), baris surel/alamat kantor, dan kontrol daftar
 *      ("Menampilkan 1 - 4 dari total 4", "10 / page", nomor halaman);
 *   5. blok yang seluruhnya berupa markup tautan atau label pendek tanpa
 *      kalimat (menu, daftar tautan) dibuang: blok berbaris banyak, blok berisi
 *      dua tautan atau lebih, label kosakata menu/kontak, serta blok menu yang
 *      muncul berturut-turut; blok yang sama berulang dan heading tanpa isi
 *      juga dibuang.
 *
 * Pemanggil dapat memakai markdown asli bila hasil pembersihan ternyata lebih
 * pendek dari min_chunk_length (mis. halaman yang memang hanya berisi daftar
 * tautan); lihat Pipeline::processUrl().
 */
final class MarkdownCleaner
{
    /**
     * Baris label antarmuka yang isinya selalu sama di setiap halaman. Hanya
     * berlaku untuk baris TANPA penanda heading ("## Berita" tetap
     * dipertahankan karena dianggap judul bagian).
     *
     * @var list<string>
     */
    private const NOISE_LINES = [
        'home', 'beranda', 'menu', 'navigation', 'navigasi', 'nav',
        'ornament', 'ornamen', 'oramen', 'background ornament', 'gambar latar',
        'profil', 'profil kami', 'tentang', 'tentang kami', 'tentang situs',
        'berita', 'berita terkait', 'artikel terkait', 'baca juga',
        'informasi publik', 'publikasi', 'galeri', 'layanan', 'layanan publik',
        'tautan', 'tautan cepat', 'tautan terkait', 'tautan luar',
        'kontak', 'kontak kami', 'hubungi kami', 'contact us',
        'agenda', 'pengumuman', 'selengkapnya', 'lihat semua', 'lihat selengkapnya',
        'total pengunjung', 'pengunjung', 'jumlah pengunjung', 'statistik pengunjung',
        'bulan ini', 'hari ini', 'minggu ini', 'tahun ini',
        'share', 'bagikan', 'ikuti kami', 'follow us', 'media sosial', 'social media',
        'iklan', 'advertisement', 'sponsored', 'loading', 'memuat', 'mohon tunggu',
        'pencarian', 'sitemap', 'peta situs', 'privacy policy', 'kebijakan privasi',
        'syarat dan ketentuan', 'skip to content', 'lewati ke konten',
        'login', 'masuk', 'register', 'banner', 'sidebar', 'breadcrumb',
        'terbaru', 'populer', 'terpopuler', 'rekomendasi',
        // Teks antarmuka pencarian/daftar (placeholder & ajakan pada kop daftar).
        'masukkan kata kunci', 'masukkan kata kunci pencarian',
        'silahkan cari data', 'silakan cari data',
    ];

    /**
     * Awalan baris footer/hak cipta.
     *
     * @var list<string>
     */
    private const NOISE_PREFIXES = [
        '©', 'â©', 'copyright', 'hak cipta', 'all rights reserved',
        'powered by', 'didesain oleh', 'designed by', 'dikembangkan oleh',
        'developed by', 'hosting by', 'total pengunjung',
    ];

    /** Sisa teks (di luar markup tautan) maksimum agar blok dianggap menu. */
    private const NAV_RESIDUE_CHARS = 20;

    /** Panjang maksimum blok label-menu yang boleh dibuang sendiri. */
    private const LABEL_CHARS = 20;

    /** Jumlah kata maksimum untuk blok label-menu. */
    private const LABEL_WORDS = 3;

    /** Blok menu harus berurutan minimal sekian kali agar dibuang. */
    private const NAV_RUN_MIN = 2;

    /**
     * Bersihkan satu dokumen markdown.
     *
     * @return array{markdown: string, dihapus: array<string, int>}
     */
    public static function clean(string $markdown): array
    {
        $dihapus = [
            'komentar' => 0,
            'gambar' => 0,
            'label_kosong' => 0,
            'baris_noise' => 0,
            'blok_navigasi' => 0,
            'blok_duplikat' => 0,
            'judul_kosong' => 0,
        ];

        if (trim($markdown) === '') {
            return ['markdown' => $markdown, 'dihapus' => $dihapus];
        }

        // 1-3: pembersihan tingkat teks.
        $text = str_replace(["\r\n", "\r"], "\n", $markdown);
        $text = self::dropPattern('/<!--.*?-->/su', $text, $dihapus['komentar']);
        $text = self::dropPattern('/!\[[^\]]*\]\([^)\n]*\)/u', $text, $dihapus['gambar']);
        $text = self::fixLinkTargets($text);
        $text = self::dropPattern('/\[[ \t]*\]\([^)\n]*\)/u', $text, $dihapus['label_kosong']);
        $text = self::tidyLinkLabels($text);

        // 4: baris noise; baris kosong tetap disimpan sebagai batas blok.
        $baris = [];
        foreach (explode("\n", $text) as $line) {
            $line = rtrim($line);
            if (trim($line) === '') {
                $baris[] = '';
                continue;
            }

            if (self::isNoiseLine($line)) {
                $dihapus['baris_noise']++;
                continue;
            }

            $baris[] = $line;
        }

        // 5: pembersihan tingkat blok.
        $blok = self::blocks($baris);
        $blok = self::dropNavigationRuns($blok, $dihapus);
        $blok = self::dropDuplicates($blok, $dihapus);
        $blok = self::dropEmptyHeadings($blok, $dihapus);

        return [
            'markdown' => Text::normalizeWhitespace(implode("\n\n", $blok)),
            'dihapus' => $dihapus,
        ];
    }

    /**
     * Buang HANYA baris label/footer (menu, "Total Pengunjung", versi aplikasi,
     * hak cipta, surel, alamat kantor, kontrol daftar) dan heading yang menjadi
     * kosong karenanya, TANPA aturan tingkat blok lain.
     *
     * Dipakai Pipeline sebagai jaring pengaman ketika hasil pembersihan penuh
     * terlalu pendek (halaman yang hampir seluruhnya menu): markdown asli tetap
     * dipakai supaya dokumen tidak hilang, tetapi baris footer yang selalu sama
     * di setiap halaman tidak ikut ke payload Qdrant. Contoh: heading footer
     * "##### Kementerian Dalam Negeri" yang isinya (surel + alamat + versi)
     * dibuang akan ikut hilang karena tidak menyisakan isi.
     *
     * @return array{markdown: string, dihapus: int}
     */
    public static function dropBoilerplateLines(string $markdown): array
    {
        $dihapus = 0;
        $baris = [];

        foreach (explode("\n", Text::normalizeWhitespace($markdown)) as $line) {
            if (trim($line) !== '' && self::isNoiseLine($line)) {
                $dihapus++;

                continue;
            }

            $baris[] = $line;
        }

        $stat = ['judul_kosong' => 0];
        $blok = self::dropEmptyHeadings(self::blocks($baris), $stat);

        return [
            'markdown' => Text::normalizeWhitespace(implode("\n\n", $blok)),
            'dihapus' => $dihapus + $stat['judul_kosong'],
        ];
    }

    /**
     * Buang seluruh kecocokan pola sambil menghitung berapa yang dibuang.
     */
    private static function dropPattern(string $pattern, string $text, int &$count): string
    {
        $result = preg_replace_callback($pattern, static function () use (&$count): string {
            $count++;

            return '';
        }, $text);

        return $result ?? $text;
    }

    /**
     * Perbaiki destinasi tautan sisa konversi service:
     * "(\profil\sejarah)" -> "(/profil/sejarah)" dan "(\\)" -> "(/)".
     */
    private static function fixLinkTargets(string $text): string
    {
        $result = preg_replace_callback('/\]\(([^)\n]*)\)/u', static function (array $match): string {
            $target = trim(str_replace('\\', '/', $match[1]));

            if ($target !== '' && !str_starts_with($target, '/') && !str_starts_with($target, '#')
                && preg_match('#^[a-z][a-z0-9+.\-]*:#i', $target) !== 1) {
                $target = '/' . ltrim($target, '/');
            }

            return '](' . $target . ')';
        }, $text);

        return $result ?? $text;
    }

    /**
     * Rapikan label tautan: buang elipsis sisa pemotongan service dan kata yang
     * mengulang kata pertama ("Informasi Publik Informas" -> "Informasi Publik").
     */
    private static function tidyLinkLabels(string $text): string
    {
        $result = preg_replace_callback(
            '/\[([^\]\n]+)\]/u',
            static fn (array $match): string => '[' . self::tidyLabel($match[1]) . ']',
            $text
        );

        return $result ?? $text;
    }

    private static function tidyLabel(string $label): string
    {
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? $label);
        $label = rtrim(preg_replace('/(?:\.\.\.|…)+$/u', '', $label) ?? $label);

        if ($label === '') {
            return $label;
        }

        $kata = preg_split('/\s+/u', $label) ?: [];

        while (count($kata) > 1) {
            $akhir = mb_strtolower((string) end($kata));
            $awal = mb_strtolower((string) $kata[0]);

            if ($akhir === '' || $akhir === $awal || str_starts_with($awal, $akhir)) {
                array_pop($kata);
                continue;
            }

            break;
        }

        return implode(' ', $kata);
    }

    /**
     * Baris yang isinya hanya label antarmuka, pemisah, atau footer.
     */
    private static function isNoiseLine(string $line): bool
    {
        // Penanda list/quote di depan dibuang agar "- Menu" sama dengan "Menu".
        $plain = trim(preg_replace('/^\s*(?:[-*+]|\d+[.)])\s+/u', '', $line) ?? $line);
        $plain = mb_strtolower(trim($plain));

        if ($plain === '') {
            return true;
        }

        // Pemisah murni ("---", "***", "--", "•"). Baris tabel ("| --- |")
        // tidak ikut dibuang karena dibutuhkan struktur tabel.
        if (!str_contains($plain, '|') && preg_match('/^[\-–—_=*.·•~^\\\s]+$/u', $plain) === 1) {
            return true;
        }

        if (in_array($plain, self::NOISE_LINES, true)) {
            return true;
        }

        // Kata mengulang tanpa tautan: "Ornamen Ornamen", "Publikasi Publikas".
        if (preg_match('/^([\p{L}\p{N}]+)(?:\s+\1\w*)+$/u', $plain) === 1) {
            return true;
        }

        // Kata kedua yang menyalin/memotong kata pertama: "Profil Profil",
        // "Publikasi Publikas...", "Layanan Layanan Publik".
        $kata = preg_split('/\s+/u', $plain) ?: [];
        if (count($kata) >= 2 && count($kata) <= 4) {
            $awal = mb_strtolower((string) $kata[0]);

            for ($i = 1; $i < count($kata); $i++) {
                $ulang = mb_strtolower(rtrim((string) $kata[$i], '.…'));

                if ($ulang !== '' && $awal !== '' && (str_starts_with($awal, $ulang) || str_starts_with($ulang, $awal))) {
                    return true;
                }
            }
        }

        // Footer/boilerplate situs yang TIDAK selalu berada di awal baris (mis.
        // "Pelita ver 2.1.2 © 2025 All rights reserved | Satu Data ..."):
        // hak cipta, versi aplikasi, surel, dan alamat kantor. Dibatasi panjang
        // barisnya dan hanya menandai frasa khas footer ("hak cipta"/"copyright"
        // di tengah kalimat isi tidak ikut dibuang; keduanya sudah ditangani
        // sebagai awalan baris pada NOISE_PREFIXES).
        if (mb_strlen($plain) <= 160
            && preg_match('/(?:all rights reserved|Â©|©|\bver(?:si)?\.?\s*\d+(?:\.\d+)+)/u', $plain) === 1) {
            return true;
        }

        // Baris yang HANYA berisi surel ("pusdatin@kemendagri.go.id").
        if (preg_match('/^[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}.\-]+\.[\p{L}]{2,}$/u', $plain) === 1) {
            return true;
        }

        // Baris alamat kantor pada footer: "Jl. Medan Merdeka Utara No. 7
        // Jakarta Pusat, ..." / "Jalan ... No. ...".
        if (preg_match('/^(?:jl\.?|jalan)\s+.{0,80}\bno\.?\s*\d+/u', $plain) === 1) {
            return true;
        }

        // Kontrol antarmuka daftar: "Menampilkan 1 - 4 dari total 4",
        // "10 / page", serta butir penomoran halaman ("- 1", "- 12").
        if (preg_match('/^menampilkan\s+\d+\s*[-\x{2013}]\s*\d+\s+dari\s+total\s+\d+$/u', $plain) === 1) {
            return true;
        }

        if (preg_match('/^\d+\s*\/\s*(?:page|halaman)$/u', $plain) === 1) {
            return true;
        }

        if (preg_match('/^(?:[-*+]\s*)?\d{1,3}$/u', $plain) === 1) {
            return true;
        }

        foreach (self::NOISE_PREFIXES as $prefix) {
            if (str_starts_with($plain, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Kelompokkan baris menjadi blok yang dipisahkan baris kosong.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function blocks(array $lines): array
    {
        $blok = [];
        $current = [];

        foreach ($lines as $line) {
            if ($line === '') {
                if ($current !== []) {
                    $blok[] = implode("\n", $current);
                    $current = [];
                }

                continue;
            }

            $current[] = $line;
        }

        if ($current !== []) {
            $blok[] = implode("\n", $current);
        }

        return $blok;
    }

    /**
     * Buang blok menu: blok yang isinya seluruhnya markup tautan/label pendek
     * tanpa kalimat dan muncul berturut-turut.
     *
     * Blok semacam itu yang berdiri sendiri tetap dipertahankan supaya tautan
     * penting (mis. unduhan satu PDF) tidak hilang; blok label yang SANGAT
     * pendek (<= LABEL_CHARS) dibuang walau berdiri sendiri karena hampir
     * selalu sisa menu (mis. "Republik Indonesia", "(021) 3450038").
     *
     * @param list<string>       $blok
     * @param array<string, int> $dihapus
     *
     * @return list<string>
     */
    private static function dropNavigationRuns(array $blok, array &$dihapus): array
    {
        $hasil = [];
        $index = 0;
        $jumlah = count($blok);

        while ($index < $jumlah) {
            $jenis = self::navigationKind($blok[$index]);

            if ($jenis === 'navigasi') {
                $selesai = $index;
                // Bobot = jumlah baris: satu blok daftar tautan yang memuat
                // beberapa baris sudah jelas menu walau berdiri sendiri.
                $bobot = substr_count($blok[$index], "\n") + 1;

                while ($selesai + 1 < $jumlah && self::navigationKind($blok[$selesai + 1]) === 'navigasi') {
                    $selesai++;
                    $bobot += substr_count($blok[$selesai], "\n") + 1;
                }

                if ($bobot >= self::NAV_RUN_MIN) {
                    $dihapus['blok_navigasi'] += $selesai - $index + 1;
                    $index = $selesai + 1;
                    continue;
                }
            } elseif ($jenis === 'label') {
                $dihapus['blok_navigasi']++;
                $index++;
                continue;
            }

            $hasil[] = $blok[$index];
            $index++;
        }

        return $hasil;
    }

    /**
     * Klasifikasi blok: 'navigasi' (daftar tautan/menu), 'label' (label sangat
     * pendek tanpa isi), atau 'isi' (paragraf, tabel, heading).
     */
    private static function navigationKind(string $block): string
    {
        $trimmed = trim($block);
        if ($trimmed === '') {
            return 'isi';
        }

        // Heading dan tabel tidak pernah dianggap menu.
        if (preg_match('/^#{1,6}\s/u', $trimmed) === 1 || str_starts_with($trimmed, '|')) {
            return 'isi';
        }

        $jumlahTautan = (int) preg_match_all('/\]\([^)\n]*\)/u', $block);
        $adaTautan = $jumlahTautan > 0;
        $residu = self::residueText($block);
        $panjang = mb_strlen($residu);
        $kalimat = preg_match('/[.!?:;]$/u', $residu) === 1;

        if ($adaTautan && $panjang <= self::NAV_RESIDUE_CHARS && !$kalimat) {
            if ($panjang === 0) {
                // Tanpa teks apa pun di luar tautan: dua tautan atau lebih =
                // baris menu; satu tautan dibuang hanya bila labelnya kosakata
                // menu/kontak (satu tautan lain, mis. unduhan PDF, tetap aman).
                if ($jumlahTautan >= 2 || self::isNavVocabulary($block) || self::isContactLink($block)) {
                    return 'label';
                }
            }

            return 'navigasi';
        }

        if (!$adaTautan && $panjang > 0 && $panjang <= self::LABEL_CHARS) {
            $kata = count(preg_split('/\s+/u', $residu) ?: []);

            if ($kata <= self::LABEL_WORDS
                && (preg_match('/\d/u', $residu) !== 1 || preg_match('/^[\d\s()+\-.,\/–—]+$/u', $residu) === 1)) {
                return 'label';
            }
        }

        return 'isi';
    }

    /**
     * Teks blok di luar markup tautan (bullet/penekanan/penanda tabel juga
     * dibuang) — dipakai untuk menilai apakah blok hanya berisi menu.
     */
    private static function residueText(string $block): string
    {
        $text = preg_replace('/\[[^\]\n]*\]\([^)\n]*\)/u', ' ', $block) ?? $block;
        $text = preg_replace('/[#*_`>|~]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/^\s*(?:[-*+]|\d+[.)])\s+/mu', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Apakah SEMUA label tautan pada blok termasuk kosakata menu (NOISE_LINES).
     */
    private static function isNavVocabulary(string $block): bool
    {
        if (preg_match_all('/\[([^\]\n]*)\]\([^)\n]*\)/u', $block, $matches) !== false && ($matches[1] ?? []) !== []) {
            foreach ($matches[1] as $label) {
                $label = mb_strtolower(self::tidyLabel((string) $label));

                if ($label === '' || !in_array($label, self::NOISE_LINES, true)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Blok yang seluruh tautannya non-http (tel:/mailto:/sms:) = kontak footer.
     */
    private static function isContactLink(string $block): bool
    {
        $jumlah = preg_match_all('/\]\(([^)\n]*)\)/u', $block, $matches);
        if ($jumlah === false || $jumlah < 1) {
            return false;
        }

        foreach ($matches[1] as $target) {
            if (preg_match('#^(?:tel|mailto|sms|whatsapp):#i', trim((string) $target)) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Buang blok yang isinya sama dengan blok lain (menu/footer yang ter-parse
     * berulang pada halaman yang sama).
     *
     * @param list<string>       $blok
     * @param array<string, int> $dihapus
     *
     * @return list<string>
     */
    private static function dropDuplicates(array $blok, array &$dihapus): array
    {
        $hasil = [];
        $terlihat = [];

        foreach ($blok as $blokTeks) {
            $kunci = mb_strtolower(preg_replace('/\s+/u', ' ', $blokTeks) ?? $blokTeks);

            if ($kunci !== '' && isset($terlihat[$kunci])) {
                $dihapus['blok_duplikat']++;
                continue;
            }

            $terlihat[$kunci] = true;
            $hasil[] = $blokTeks;
        }

        return $hasil;
    }

    /**
     * Buang heading tanpa isi: diikuti heading lain dengan tingkat yang sama
     * atau lebih dangkal (mis. menu footer yang isinya sudah dibuang sehingga
     * menyisakan "#### Tautan Cepat" kosong sebelum "#### Layanan").
     *
     * @param list<string>       $blok
     * @param array<string, int> $dihapus
     *
     * @return list<string>
     */
    private static function dropEmptyHeadings(array $blok, array &$dihapus): array
    {
        $hasil = [];
        $jumlah = count($blok);

        for ($index = 0; $index < $jumlah; $index++) {
            if (preg_match('/^\s{0,3}(#{1,6})\s+/u', $blok[$index], $match) === 1) {
                $tingkat = strlen($match[1]);
                $kosong = true;

                if ($index + 1 < $jumlah) {
                    if (preg_match('/^\s{0,3}(#{1,6})\s+/u', $blok[$index + 1], $next) === 1) {
                        $kosong = strlen($next[1]) <= $tingkat;
                    } else {
                        $kosong = false;
                    }
                }

                if ($kosong) {
                    $dihapus['judul_kosong']++;
                    continue;
                }
            }

            $hasil[] = $blok[$index];
        }

        return $hasil;
    }
}
