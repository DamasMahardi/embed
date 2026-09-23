<?php

declare(strict_types=1);

namespace App\Crawl;

use App\Http\HttpClient;

/**
 * Dukungan tautan Google Drive pada halaman yang di-crawl.
 *
 * Tiga bentuk tautan yang dikenali:
 *   FOLDER  https://drive.google.com/drive/folders/<ID>            (atau .../u/0/folders/<ID>)
 *           https://drive.google.com/embeddedfolderview?id=<ID>#list
 *   FILE    https://drive.google.com/file/d/<ID>/view              (atau open?id=ID, uc?id=ID)
 *   NATIVE  https://docs.google.com/document/d/<ID>/edit           (Docs/Sheets/Slides)
 *
 * Folder publik dibaca TANPA API key dengan dua cara:
 *   1. embeddedfolderview?id=<ID>#list  -> markup entri flip-entry;
 *   2. halaman /drive/folders/<ID>      -> payload window['_DRIVE_ivd'] (cadangan).
 * Setiap berkas lalu diunduh lewat drive.usercontent.googledownload (menangani
 * halaman konfirmasi "virus scan" untuk berkas besar); berkas Google Docs
 * (native) diekspor ke Office/PDF agar bisa dibaca /parse.
 *
 * Hasil unduhan dikembalikan sebagai FetchResult sehingga Pipeline
 * memperlakukannya persis seperti berkas dokumen biasa (parse -> chunk ->
 * embed -> Qdrant).
 */
final class GoogleDrive
{
    /** Batas jumlah berkas per folder (jaring pengaman). */
    public const MAX_FILES = 500;

    public function __construct(private HttpClient $http)
    {
    }

    /**
     * Kenali tautan Google Drive/Docs.
     *
     * @return array{tipe: string, id: string, format: string, folder: bool}|null
     */
    public static function target(string $url): ?array
    {
        $parts = parse_url(trim($url));

        if (!is_array($parts)) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $query = [];

        parse_str((string) ($parts['query'] ?? ''), $query);
        $idQuery = (string) ($query['id'] ?? '');

        if ($host === 'docs.google.com') {
            if (preg_match('#^/(document|spreadsheets|presentation)/d/([A-Za-z0-9_-]{10,})#', $path, $c) !== 1) {
                return null;
            }

            $format = ['document' => 'docx', 'spreadsheets' => 'xlsx', 'presentation' => 'pptx'][$c[1]];

            return ['tipe' => 'native', 'id' => $c[2], 'format' => $format, 'folder' => false];
        }

        if (!in_array($host, ['drive.google.com', 'drive.usercontent.google.com'], true)) {
            return null;
        }

        if (preg_match('#/(?:drive/)?(?:u/\d+/)?folders/([A-Za-z0-9_-]{10,})#', $path, $c) === 1) {
            return ['tipe' => 'folder', 'id' => $c[1], 'format' => 'folder', 'folder' => true];
        }

        if (preg_match('#/file/d/([A-Za-z0-9_-]{10,})#', $path, $c) === 1) {
            return ['tipe' => 'file', 'id' => $c[1], 'format' => '', 'folder' => false];
        }

        // embeddedfolderview?id=... , open?id=... , uc?id=... , drive/folders?id=...
        if ($idQuery !== '' && preg_match('#^/?$|embeddedfolderview|/drive|/uc|/open#', $path) === 1) {
            $tipe = str_contains($path, 'embeddedfolderview') || str_contains($path, 'folders') ? 'folder' : 'file';

            return [
                'tipe' => $tipe,
                'id' => $idQuery,
                'format' => $tipe === 'folder' ? 'folder' : '',
                'folder' => $tipe === 'folder',
            ];
        }

        return null;
    }

    /**
     * URL ini tautan Google Drive yang dikenal?
     */
    public static function isDriveUrl(string $url): bool
    {
        return self::target($url) !== null;
    }

    /**
     * URL Folder Drive yang perlu DIPERLUAS (isinya diambil daftar berkasnya)
     * alih-alih diunduh sebagai halaman HTML.
     */
    public static function isFolderUrl(string $url): bool
    {
        $target = self::target($url);

        return $target !== null && $target['folder'];
    }

    /**
     * URL unduhan langsung satu berkas Drive (atau ekspor untuk berkas native).
     */
    public static function downloadUrl(string $fileId, string $format = '', string $nama = ''): string
    {
        if (in_array($format, ['docx', 'xlsx', 'pptx'], true) && $nama === '') {
            // Tanpa nama berkas: ekspor ke format Office lewat docs.google.com.
            $jenis = ['docx' => 'document', 'xlsx' => 'spreadsheets', 'pptx' => 'presentation'][$format];

            return 'https://docs.google.com/' . $jenis . '/d/' . $fileId . '/export?format=' . $format;
        }

        // Berkas biner (atau ekspor yang menyertakan nama berkas):
        // confirm=t melewati halaman peringatan pemindaian virus.
        return 'https://drive.usercontent.google.com/download?id=' . rawurlencode($fileId) . '&export=download&confirm=t';
    }

    /**
     * Daftar isi folder publik (tanpa API key).
     *
     * @return array{berkas: list<array{id: string, nama: string}>, sumber: string, galat: string}
     */
    public function listFolder(string $folderId, int $maxFiles = self::MAX_FILES): array
    {
        $batas = max(1, min(self::MAX_FILES, $maxFiles));

        // Cara 1: daftar HTML sederhana yang dibuat Google untuk folder publik.
        $respons = $this->http->get('https://drive.google.com/embeddedfolderview?id=' . rawurlencode($folderId) . '#list', [
            'timeout' => 45,
            'headers' => ['Accept' => 'text/html,application/xhtml+xml'],
        ]);

        $berkas = self::parseFolderHtml($respons->body ?? '', $batas);

        if ($berkas !== []) {
            return ['berkas' => $berkas, 'sumber' => 'embeddedfolderview', 'galat' => ''];
        }

        // Cara 2: halaman folder biasa (payload JSON internal halaman).
        $respons2 = $this->http->get('https://drive.google.com/drive/folders/' . rawurlencode($folderId), [
            'timeout' => 45,
            'headers' => ['Accept' => 'text/html,application/xhtml+xml'],
        ]);

        $berkas2 = self::parseFolderHtml((string) ($respons2->body ?? ''), $batas);

        if ($berkas2 !== []) {
            return ['berkas' => $berkas2, 'sumber' => 'drive-folders', 'galat' => ''];
        }

        return [
            'berkas' => [],
            'sumber' => '',
            'galat' => 'isi folder tidak terbaca (pastikan folder berbagi "Anyone with the link" / publik)',
        ];
    }

    /**
     * Uraikan daftar berkas dari HTML folder Drive.
     *
     * Mendukung dua bentuk:
     *   1. markup embeddedfolderview : id="entry-<ID>" + class="flip-entry-title";
     *   2. payload window['_DRIVE_ivd'] pada halaman /drive/folders/<ID>
     *      (array berisi ["<ID>",["<parent>"],"<nama>","<mime>",...]).
     *
     * @return list<array{id: string, nama: string}>
     */
    public static function parseFolderHtml(string $html, int $maxFiles = self::MAX_FILES): array
    {
        if (trim($html) === '') {
            return [];
        }

        $berkas = [];
        $sudah = [];

        // Bentuk 1: entri embeddedfolderview.
        if (preg_match_all(
            '#id="entry-([A-Za-z0-9_-]{10,})".*?class="flip-entry-title"[^>]*>([^<]+)<#s',
            $html,
            $cocok,
            PREG_SET_ORDER
        ) > 0) {
            foreach ($cocok as $c) {
                $id = $c[1];

                if (isset($sudah[$id])) {
                    continue;
                }

                $sudah[$id] = true;
                $berkas[] = ['id' => $id, 'nama' => html_entity_decode(trim($c[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')];

                if (count($berkas) >= $maxFiles) {
                    return $berkas;
                }
            }
        }

        // Bentuk 2: payload _DRIVE_ivd (nama berkas berada setelah path induk).
        if (preg_match("/window\\['_DRIVE_ivd'\\]\\s*=\\s*'(.*?)';/s", $html, $payload) === 1) {
            $isi = stripcslashes($payload[1]);
            $isi = str_replace('\\"', '"', $isi);

            if (preg_match_all('/"([A-Za-z0-9_-]{10,})",\["?[^]]*?"?\],"([^"]{1,200})","([^"]*)"/', $isi, $cocok2, PREG_SET_ORDER) > 0) {
                foreach ($cocok2 as $c) {
                    $id = $c[1];

                    if (isset($sudah[$id]) || $c[2] === '') {
                        continue;
                    }

                    $sudah[$id] = true;
                    $berkas[] = ['id' => $id, 'nama' => html_entity_decode(trim($c[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')];

                    if (count($berkas) >= $maxFiles) {
                        return $berkas;
                    }
                }
            }
        }

        return $berkas;
    }
}

