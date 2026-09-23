<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\Crawl\SiteConfig;
use App\Support\Text;
use App\Support\Uuid;

/**
 * Pemecah markdown menjadi chunk berukuran tetap dengan tumpang tindih
 * (overlap), mengikuti perilaku crawl-web/src/chunker.ts supaya vektor yang
 * dihasilkan setara.
 *
 * Setiap chunk membawa payload vektor dengan skema tetap (kunci dan urutannya
 * sama seperti contoh di README) sehingga berkas .qdrant.json bisa langsung
 * dikirim ke Qdrant sebagai PointStruct:
 *
 *   document_id, chunk_id, chunk_no, checksum, title, url, page, heading,
 *   section, content, document_type, province, city, year, source, sha256,
 *   object_key, file_url, status, created_at, updated_at
 *
 * Nilai field payload mengikuti bentuk yang sama dengan pipeline dokumen lain
 * (mis. potongan dari PPID Kemendagri):
 *   - chunk_no dihitung dari 0, bukan 1;
 *   - chunk_id deterministik "<document_id>:<chunk_no>" (mis.
 *     "2ae2f337-e7c1-41e3-a744-7bcb8146f58c:0") sehingga unggahan ulang /
 *     re-crawl menimpa point yang sama, bukan menumpuk duplikat;
 *   - checksum = sha256 hex isi chunk (tanpa prefiks "sha256:");
 *   - field metadata yang tidak diisi di sites.json bernilai null, bukan ""
 *     (document_type, province, city, year);
 *   - section = label metadata ("APBD 2024") atau, bila entri tidak punya
 *     metadata, heading pertama dokumen (PDF hasil /parse: "Document");
 *   - page = nomor halaman PDF asal chunk, dibaca dari penanda "## Page <n>"
 *     yang ditulis parse_pdf.py pada markdown dokumen (halaman HTML tetap null
 *     karena tidak punya penanda halaman).
 *
 * created_at/updated_at ditulis dalam ISO-8601 UTC bermilidetik
 * (mis. 2026-09-16T10:00:00.000Z) agar tidak ambigu antar zona waktu; jam pada
 * log/UI tetap mengikuti APP_TIMEZONE.
 *
 * Sebagai jaring pengaman kedua setelah MarkdownCleaner, jendela yang tidak
 * menyisakan teks nyata (min_chunk_length) atau yang porsi markup tautannya
 * melebihi SiteConfig::$maxLinkRatio (ciri menu/daftar isi) tidak dijadikan
 * chunk, sehingga point Qdrant hanya berisi isi halaman.
 */
final class MarkdownChunker
{
    /**
     * Identitas dokumen deterministik dari URL (UUID v5) — URL yang sama akan
     * selalu menghasilkan document_id yang sama sehingga re-crawl menimpa
     * dokumen lama, bukan membuat duplikat.
     */
    public static function documentIdForUrl(string $url): string
    {
        return Uuid::v5($url, Uuid::DOCUMENT_NAMESPACE);
    }

    /**
     * SHA-256 isi dokumen (untuk mendeteksi perubahan isi pada re-crawl).
     */
    public static function documentSha256(string $markdown): string
    {
        return hash('sha256', $markdown);
    }

    /**
     * Pecah teks panjang menjadi jendela ~$chunkSize karakter dengan overlap.
     *
     * @return list<string>
     */
    public static function windowText(string $text, int $chunkSize, int $overlap): array
    {
        return array_map(
            static fn (array $window): string => $window['text'],
            self::windows($text, $chunkSize, $overlap)
        );
    }

    /**
     * Sama seperti windowText(), tetapi tiap jendela disertai posisi awalnya
     * (offset karakter) sehingga judul bagian (heading) dapat ditentukan.
     *
     * @return list<array{text: string, start: int}>
     */
    public static function windows(string $text, int $chunkSize, int $overlap): array
    {
        $length = mb_strlen($text);
        if ($length <= $chunkSize) {
            $trimmed = trim($text);

            return $trimmed === '' ? [] : [['text' => $trimmed, 'start' => 0]];
        }

        $windows = [];
        $start = 0;

        while ($start < $length) {
            $end = min($start + $chunkSize, $length);
            $cut = $end;

            if ($end < $length) {
                $lookback = mb_substr($text, $start, $end - $start);
                $lastParagraph = mb_strrpos($lookback, "\n\n");
                $lastSentence = mb_strrpos($lookback, '. ');

                // utamakan pemotongan di batas paragraf/kalimat terdekat.
                $lastBreak = max(
                    $lastParagraph === false ? -1 : $lastParagraph,
                    $lastSentence === false ? -1 : $lastSentence
                );

                if ($lastBreak > $chunkSize * 0.5) {
                    $cut = $start + $lastBreak + 1;
                }
            }

            $piece = trim(mb_substr($text, $start, $cut - $start));
            if ($piece !== '') {
                $windows[] = ['text' => $piece, 'start' => $start];
            }

            if ($cut >= $length) {
                break;
            }

            $start = max($cut - $overlap, $start + 1);
        }

        return $windows;
    }

    /**
     * Bersihkan satu jendela chunk dari markup markdown.
     *
     * Jendela diambil dari markdown hasil /parse, jadi masih memuat penanda
     * heading ("### Data Prioritas", "###### KEPUTUSAN MENTERI ...") serta
     * penekanan/tautan. Penanda itu ikut tersimpan pada payload `content` dan
     * ikut dikirim ke /embed, sehingga teks yang dikembalikan saat pencarian
     * vektor ikut membawa "#"/"**"/"[](url)".
     *
     * Fungsi ini hanya membuang PENANDANYA (baris, urutan, dan isi teks tetap):
     *   - "[Berita](https://...)"  -> "Berita";
     *   - "### Judul" dan token "######" yang berdiri sendiri -> "Judul";
     *   - "**tebal**", "__tebal__", "~~coret~~", "`kode`", "> kutipan".
     * "#" yang menempel pada huruf/angka (mis. "C#", "No. 7 #3") dibiarkan.
     */
    public static function cleanChunkText(string $text): string
    {
        // 1. Markup tautan -> labelnya saja.
        $text = preg_replace('/\[([^\]\n]*)\]\([^)\n]*\)/u', '$1', $text) ?? $text;
        $text = preg_replace('/\[([^\]\n]*)\]/u', '$1', $text) ?? $text;

        // 2. Penanda heading: di awal baris, dan token yang berdiri sendiri di
        //    tengah baris ("Data ###### KEPUTUSAN").
        $text = preg_replace('/^[ \t]{0,3}#{1,6}[ \t]*/mu', '', $text) ?? $text;
        $text = preg_replace('/(?<![\p{L}\p{N}])#{1,6}(?![\p{L}\p{N}])/u', '', $text) ?? $text;

        // 3. Penekanan, kode inline, dan penanda kutipan di awal baris.
        $text = preg_replace('/\*\*([^*\n]+)\*\*/u', '$1', $text) ?? $text;
        $text = preg_replace('/__([^_\n]+)__/u', '$1', $text) ?? $text;
        $text = preg_replace('/~~([^~\n]+)~~/u', '$1', $text) ?? $text;
        $text = preg_replace('/`([^`\n]+)`/u', '$1', $text) ?? $text;
        $text = preg_replace('/^[ \t]{0,3}>[ \t]?/mu', '', $text) ?? $text;

        // 4. Rapikan spasi sisa penanda ("##  Judul" -> "Judul").
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/[ \t]+\n/u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Bangun seluruh chunk + payload dari satu dokumen hasil /parse.
     *
     * @param string|null $createdAt stempel ISO-8601 UTC buatan pemanggil
     *                               (payload.created_at); null = waktu sekarang
     * @param string|null $objectKey lokasi berkas mentah (payload.object_key)
     * @param array<string, int>|null $dibuang statistik chunk yang tidak dipakai
     *                               ("pendek", "tanpa_isi", "tautan"); diisi bila
     *                               pemanggil mengirim variabel
     * @param string|null $fileUrl URL berkas mentah untuk payload.file_url
     *                             (diisi untuk dokumen: PDF/lampiran yang
     *                             diunduh crawler); null untuk halaman HTML
     *
     * @return list<Chunk>
     */
    public static function build(
        string $title,
        string $url,
        SiteConfig $site,
        string $markdown,
        ?string $createdAt = null,
        ?string $objectKey = null,
        ?array &$dibuang = null,
        ?string $fileUrl = null,
    ): array {
        $dibuang = ['pendek' => 0, 'tanpa_isi' => 0, 'tautan' => 0];

        $markdown = Text::normalizeWhitespace($markdown);
        if ($markdown === '') {
            return [];
        }

        $documentId = self::documentIdForUrl($url);
        $documentSha = self::documentSha256($markdown);
        $now = self::isoUtc();
        $headings = self::headings($markdown);
        $pageMarkers = self::pageMarkers($markdown);
        // Label bagian: label metadata ("APBD 2024") atau, bila entri tidak
        // punya metadata, heading pertama dokumen supaya payload tidak berisi
        // section kosong (untuk PDF hasil /parse: "Document").
        $section = $site->sectionLabel() ?? ($headings[0]['text'] ?? null);
        $chunks = [];
        // Nomor chunk mulai dari 0 seperti payload pipeline dokumen lain.
        $chunkNo = -1;

        foreach (self::windows($markdown, $site->chunkSize, $site->chunkOverlap) as $window) {
            $piece = $window['text'];

            if (mb_strlen($piece) < $site->minChunkLength) {
                $dibuang['pendek']++;
                continue;
            }

            // Jaring pengaman kedua setelah MarkdownCleaner: jendela yang hampir
            // seluruhnya markup tautan (menu/daftar isi) atau tidak menyisakan
            // teks nyata tidak perlu dikirim ke /embed maupun disimpan sebagai
            // point Qdrant.
            if (self::plainLength($piece) < $site->minChunkLength) {
                $dibuang['tanpa_isi']++;
                continue;
            }

            if (self::linkRatio($piece) > $site->maxLinkRatio) {
                $dibuang['tautan']++;
                continue;
            }

            // `content` payload (dan masukan /embed) memakai teks BERSIH: penanda
            // markdown dibuang agar point Qdrant tidak berisi "###"/"**".
            $content = self::cleanChunkText($piece);

            if (mb_strlen($content) < $site->minChunkLength) {
                $dibuang['tanpa_isi']++;
                continue;
            }

            $chunkNo++;
            $checksum = hash('sha256', $content);
            // chunk_id deterministik "<document_id>:<chunk_no>" seperti payload
            // pipeline dokumen lain: identitas chunk stabil sehingga unggahan
            // ulang menimpa point yang sama. Id point Qdrant dihitung dari
            // pasangan document_id + chunk_no di Qdrant\VectorFileReader.
            $chunkId = $documentId . ':' . $chunkNo;

            $payload = [
                'document_id' => $documentId,
                'chunk_id' => $chunkId,
                'chunk_no' => $chunkNo,
                'checksum' => $checksum,
                'title' => $title,
                'url' => $url,
                'page' => self::pageAt($pageMarkers, $window['start']),
                'heading' => self::headingAt($headings, $window['start'], $piece),
                'section' => $section,
                'content' => $content,
                // Metadata entri yang tidak diisi dikirim sebagai null (bukan
                // "" atau "WEB") supaya tipe field seragam di collection.
                'document_type' => $site->payloadDocumentType(),
                'province' => self::textOrNull($site->metadataFor('province')),
                'city' => self::textOrNull($site->metadataFor('city')),
                'year' => self::scalarOrNull($site->metadataFor('year')),
                'source' => Text::hostname($url),
                'sha256' => $documentSha,
                'object_key' => $objectKey,
                'file_url' => self::textOrNull($fileUrl),
                'status' => 'READY',
                'created_at' => $createdAt ?? $now,
                'updated_at' => $now,
            ];

            $chunks[] = new Chunk(
                documentId: $documentId,
                chunkId: $chunkId,
                chunkNo: $chunkNo,
                checksum: $checksum,
                content: $content,
                payload: $payload,
            );
        }

        return $chunks;
    }

    /**
     * Nilai metadata teks untuk payload: nilai kosong/tidak diisi menjadi null
     * (bukan "") supaya tipe field seragam di seluruh collection.
     */
    private static function textOrNull(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * Nilai metadata angka/teks untuk payload: year 2024 tetap int, tahun kosong
     * menjadi null (tipe field di collection tetap konsisten).
     */
    private static function scalarOrNull(mixed $value): string|int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        return is_string($value) ? self::textOrNull($value) : null;
    }

    /**
     * Panjang teks nyata sebuah jendela: markup tautan diganti labelnya dan
     * penanda markdown dibuang, sehingga menu/daftar isi tidak dihitung sebagai
     * isi.
     */
    private static function plainLength(string $text): int
    {
        return mb_strlen(self::plainText($text));
    }

    /**
     * Bagian karakter (tanpa spasi) yang merupakan markup tautan, mis. 0.75
     * berarti 75% jendela hanya berisi tautan — ciri menu/daftar isi.
     */
    private static function linkRatio(string $text): float
    {
        $padat = preg_replace('/\s+/u', '', $text) ?? $text;
        $total = mb_strlen($padat);

        if ($total === 0) {
            return 0.0;
        }

        $nyata = mb_strlen(preg_replace('/\s+/u', '', self::plainText($text)) ?? '');

        return $nyata >= $total ? 0.0 : ($total - $nyata) / $total;
    }

    /**
     * Teks jendela tanpa markup: label tautan dipertahankan, penanda
     * heading/penekanan/tabel dibuang.
     */
    private static function plainText(string $text): string
    {
        $plain = preg_replace('/\[([^\]\n]*)\]\([^)\n]*\)/u', '$1', $text) ?? $text;
        $plain = preg_replace('/[#*_`>|~]+/u', ' ', $plain) ?? $plain;

        return trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain);
    }

    /**
     * Daftar heading markdown (`# Judul`) beserta offset karakter awalnya.
     *
     * @return list<array{offset: int, text: string}>
     */
    private static function headings(string $markdown): array
    {
        $headings = [];
        $offset = 0;

        foreach (explode("\n", $markdown) as $line) {
            if (preg_match('/^\s{0,3}#{1,6}\s+(.+)$/u', $line, $match) === 1) {
                $text = self::cleanHeading($match[1]);

                if ($text !== '') {
                    $headings[] = ['offset' => $offset, 'text' => $text];
                }
            }

            $offset += mb_strlen($line) + 1;
        }

        return $headings;
    }

    /**
     * Penanda halaman pada markdown hasil /parse untuk berkas DOKUMEN:
     * "# Document" + "## Page <n>" (ditulis parse_pdf.py). Dipakai untuk mengisi
     * payload.page sehingga tiap chunk tahu halaman PDF asalnya.
     *
     * @return list<array{offset: int, page: int}>
     */
    private static function pageMarkers(string $markdown): array
    {
        $markers = [];
        $offset = 0;

        foreach (explode("\n", $markdown) as $line) {
            if (preg_match('/^\s{0,3}#{1,6}\s+Page\s+(\d+)\s*$/iu', $line, $match) === 1) {
                $markers[] = ['offset' => $offset, 'page' => (int) $match[1]];
            }

            $offset += mb_strlen($line) + 1;
        }

        return $markers;
    }

    /**
     * Halaman PDF yang memuat potongan teks mulai $start: penanda halaman
     * terakhir sebelum $start. Bila chunk dimulai sebelum penanda pertama
     * (mis. judul "# Document"), halaman pertama dipakai supaya isi awal
     * dokumen tidak kehilangan nomor halaman.
     *
     * @param list<array{offset: int, page: int}> $markers
     */
    private static function pageAt(array $markers, int $start): ?int
    {
        if ($markers === []) {
            return null;
        }

        $page = $markers[0]['page'];

        foreach ($markers as $marker) {
            if ($marker['offset'] > $start) {
                break;
            }

            $page = $marker['page'];
        }

        return $page;
    }

    /**
     * Heading yang berlaku untuk satu jendela: heading terakhir sebelum awal
     * jendela, atau heading pertama di dalam jendela bila belum ada.
     *
     * @param list<array{offset: int, text: string}> $headings
     */
    private static function headingAt(array $headings, int $start, string $piece): ?string
    {
        $end = $start + mb_strlen($piece);
        $before = null;
        $inside = null;

        foreach ($headings as $heading) {
            if ($heading['offset'] <= $start) {
                $before = $heading['text'];

                continue;
            }

            if ($heading['offset'] < $end) {
                $inside = $heading['text'];

                continue;
            }

            break;
        }

        return $before ?? $inside;
    }

    /**
     * Buang penanda markdown pada judul heading (`**`, `_`, backtick, `#`).
     */
    private static function cleanHeading(string $text): string
    {
        $text = preg_replace('/[#*_`]+/u', '', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Stempel waktu ISO-8601 UTC dengan milidetik, mis. 2026-09-16T10:00:00.000Z.
     *
     * Payload vektor memakai UTC agar tidak ambigu, sedangkan jam pada log/UI
     * mengikuti APP_TIMEZONE (default Asia/Jakarta).
     */
    private static function isoUtc(?float $timestamp = null): string
    {
        $timestamp ??= microtime(true);
        $seconds = (int) floor($timestamp);
        $millis = min(999, max(0, (int) round(($timestamp - $seconds) * 1000)));

        return gmdate('Y-m-d\TH:i:s', $seconds) . sprintf('.%03dZ', $millis);
    }
}
