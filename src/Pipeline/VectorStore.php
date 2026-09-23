<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\Crawl\SiteConfig;
use App\Qdrant\QdrantJsonExporter;
use App\Support\Text;

/**
 * Penyimpan hasil crawl ke disk:
 *
 *   storage/html/<site>/<slug>--<hash8>.html         HTML mentah (audit / ulang parse)
 *   storage/documents/<site>/<slug>--<hash8>.<ext>   dokumen mentah (pdf/docx/...) apa adanya
 *   storage/markdown/<site>/<slug>--<hash8>.md       Markdown hasil parse (mudah dibaca)
 *   storage/vectors/<site>/<run-id>.jsonl            1 baris = 1 chunk + vektor
 *   storage/vectors/<site>/<run-id>.qdrant.json      satu berkas JSON siap kirim ke Qdrant
 *   storage/vectors/<site>/<run-id>.manifest.json    ringkasan berkas vektor
 */
final class VectorStore
{
    /**
     * @param array<string, mixed> $paths bagian config "paths"
     */
    public function __construct(
        private array $paths,
        private bool $enabled = true,
    ) {
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function htmlPath(SiteConfig $site, string $url, string $extension): string
    {
        return $this->documentPath((string) $this->paths['html'], $site, $url, $extension);
    }

    /**
     * Berkas mentah hasil unduhan untuk satu URL:
     *   halaman biasa   -> storage/html/<site>/<slug>--<hash8>.html
     *   berkas dokumen  -> storage/documents/<site>/<slug>--<hash8>.pdf (dst.)
     *
     * Ekstensi asli dokumen dipertahankan (mis. .pdf) karena layanan /parse
     * memilih parser berdasarkan ekstensi berkas yang diunggah.
     */
    public function rawPath(SiteConfig $site, string $url): string
    {
        $extension = SiteConfig::extensionFor($url);

        if ($extension === 'html') {
            return $this->htmlPath($site, $url, 'html');
        }

        $base = (string) ($this->paths['documents'] ?? $this->defaultDocumentsDir());

        return $this->documentPath($base, $site, $url, $extension);
    }

    public function markdownPath(SiteConfig $site, string $url): string
    {
        return $this->documentPath((string) $this->paths['markdown'] ?? $this->defaultMarkdownDir(), $site, $url, 'md');
    }

    /**
     * Berkas dokumen mentah dengan ekstensi yang ditentukan pemanggil.
     *
     * Dipakai untuk URL yang baru diketahui sebagai berkas dokumen SETELAH
     * diunduh (endpoint unduhan tanpa ekstensi, mis.
     * "/front/dokumen/download/400470451" -> storage/documents/<site>/...pdf):
     * berkasnya dipindahkan ke folder dokumen seperti berkas .pdf biasa.
     */
    public function documentRawPath(SiteConfig $site, string $url, string $extension): string
    {
        $base = (string) ($this->paths['documents'] ?? $this->defaultDocumentsDir());

        return $this->documentPath($base, $site, $url, strtolower(ltrim($extension, '.')));
    }

    /**
     * Folder berkas DOKUMEN mentah satu site (storage/documents/<site-id>).
     *
     * Dipakai pemrosesan ULANG berkas yang sudah ada di disk (mis. PDF yang
     * dulu gagal diparse karena service /parse mati, atau PDF yang tidak
     * sempat diproses karena batas max_pages) supaya tidak perlu unduh lagi.
     * Folder TIDAK dibuat di sini: pemanggil memeriksa is_dir() lebih dulu.
     */
    public function documentsDir(string $siteId): string
    {
        return rtrim((string) ($this->paths['documents'] ?? $this->defaultDocumentsDir()), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . Text::slug($siteId, 60);
    }

    /**
     * Folder markdown satu site (storage/markdown/<site-id>): header
     * "- sumber: <url>" pada berkas di sini adalah sumber tunggal yang paling
     * dapat dipercaya untuk memulihkan URL asli sebuah dokumen.
     */
    public function markdownDir(string $siteId): string
    {
        return rtrim((string) ($this->paths['markdown'] ?? $this->defaultMarkdownDir()), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . Text::slug($siteId, 60);
    }

    /**
     * Folder HTML mentah satu site (storage/html/<site-id>).
     */
    public function htmlDir(string $siteId): string
    {
        return rtrim((string) ($this->paths['html'] ?? base_path('storage/html')), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . Text::slug($siteId, 60);
    }

    public function vectorPath(SiteConfig $site, string $runId): string
    {
        $dir = rtrim((string) $this->paths['vectors'], DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . Text::slug($site->id, 60);

        ensure_dir($dir);

        return $dir . DIRECTORY_SEPARATOR . $runId . '.jsonl';
    }

    public function manifestPath(SiteConfig $site, string $runId): string
    {
        $dir = rtrim((string) $this->paths['vectors'], DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . Text::slug($site->id, 60);

        ensure_dir($dir);

        return $dir . DIRECTORY_SEPARATOR . $runId . '.manifest.json';
    }

    /**
     * Berkas JSON tunggal siap kirim ke Qdrant ({"points":[...]}) yang
     * mendampingi .jsonl. Aturan penamaannya ada di QdrantJsonExporter supaya
     * pipeline, CLI, dan UI memakai nama berkas yang sama.
     */
    public function qdrantPath(SiteConfig $site, string $runId): string
    {
        return QdrantJsonExporter::defaultPath($this->vectorPath($site, $runId));
    }

    /**
     * Nama berkas deterministik per URL: <slug>--<hash8>.ext
     */
    public function documentPath(string $baseDir, SiteConfig $site, string $url, string $extension): string
    {
        $dir = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . Text::slug($site->id, 60);
        ensure_dir($dir);

        $documentId = MarkdownChunker::documentIdForUrl($url);
        $slug = Text::slug($this->labelFromUrl($url), 80);

        return $dir . DIRECTORY_SEPARATOR . $slug . '--' . substr(str_replace('-', '', $documentId), 0, 8) . '.' . $extension;
    }

    /**
     * Tulis markdown hasil parse (manusiawi, mudah dibuka di editor).
     */
    public function writeMarkdown(string $path, string $url, string $title, string $markdown, array $meta = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $header = [
            '# ' . ($title !== '' ? $title : $url),
            '',
            '- sumber: ' . $url,
            '- diparse: ' . date('Y-m-d H:i:s'),
        ];

        foreach ($meta as $key => $value) {
            if ($value !== null && $value !== '') {
                $header[] = '- ' . $key . ': ' . (string) $value;
            }
        }

        $header[] = '';
        $header[] = '---';
        $header[] = '';

        ensure_dir(dirname($path));
        @file_put_contents($path, implode(PHP_EOL, $header) . $markdown . PHP_EOL, LOCK_EX);
    }

    /**
     * Tulis seluruh chunk + vektor satu dokumen sebagai JSON Lines.
     *
     * @param list<array<string, mixed>> $records
     */
    public function appendVectors(string $path, array $records): int
    {
        if (!$this->enabled || $records === []) {
            return 0;
        }

        ensure_dir(dirname($path));

        $lines = '';
        foreach ($records as $record) {
            $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded !== false) {
                $lines .= $encoded . PHP_EOL;
            }
        }

        return @file_put_contents($path, $lines, FILE_APPEND | LOCK_EX) === false ? 0 : count($records);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public function writeManifest(string $path, array $manifest): void
    {
        if (!$this->enabled) {
            return;
        }

        ensure_dir(dirname($path));

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        @file_put_contents($path, ($json === false ? '{}' : $json) . PHP_EOL, LOCK_EX);
    }

    public function relative(string $absolutePath): string
    {
        $base = str_replace('\\', '/', base_path());

        return str_replace($base . '/', '', str_replace('\\', '/', $absolutePath));
    }

    private function defaultMarkdownDir(): string
    {
        return rtrim((string) ($this->paths['storage'] ?? base_path('storage')), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'markdown';
    }

    private function defaultDocumentsDir(): string
    {
        return rtrim((string) ($this->paths['storage'] ?? base_path('storage')), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'documents';
    }

    /**
     * Label ramah dari URL: segmen path terakhir, atau host bila kosong.
     */
    private function labelFromUrl(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $path = trim($path, '/');

        if ($path === '') {
            return Text::hostname($url) . '-beranda';
        }

        $segments = explode('/', $path);
        $label = (string) end($segments);

        return $label === '' ? $path : $label;
    }
}
