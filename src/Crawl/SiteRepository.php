<?php

declare(strict_types=1);

namespace App\Crawl;

use App\Support\Config;
use App\Support\Text;
use RuntimeException;

/**
 * Memuat daftar target yang boleh diproses dari config/sites.json.
 *
 * Dua bentuk berkas didukung:
 *
 *   1. Array di level teratas (dipakai untuk daftar dokumen) - satu objek =
 *      satu URL:
 *
 *        [ {"url":"https://.../laporan-anggaran-2024.pdf","documentType":"APBD",
 *           "province":"Banten","city":"Kabupaten Serang","year":2024}, ... ]
 *
 *   2. Objek dengan "defaults" + "sites" (kompatibel dengan versi sebelumnya):
 *
 *        { "defaults": {...}, "sites": [ {"id":"kemendagri", "start_urls":[...]} ] }
 *
 * Nilai pada "defaults" dipakai untuk semua site; tiap site boleh menimpanya.
 * Entri tanpa URL (mis. baris catatan) diabaikan.
 */
final class SiteRepository
{
    /** @var list<SiteConfig>|null */
    private ?array $sites = null;

    /**
     * Daftar "defaults" pada config/sites.json, sudah digabung dengan
     * konfigurasi crawler/chunk/embed di config/app.php.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        $raw = Config::sitesRaw();
        $defaults = isset($raw['defaults']) && is_array($raw['defaults']) ? $raw['defaults'] : [];

        return array_merge([
            'enabled' => true,
            'follow_links' => Config::bool('crawl.follow_links', true),
            'same_host_only' => true,
            // Nilai cadangan bila config/sites.json tidak menulis "defaults":
            // halaman TANPA BATAS (crawl.max_pages / CRAWL_MAX_PAGES, bawaan 0)
            // supaya crawl bisa maksimal, dan kedalaman mengikuti
            // CRAWL_MAX_DEPTH (0 = hanya start_urls).
            'max_pages' => max(0, Config::int('crawl.max_pages', 0)),
            'max_depth' => Config::int('crawl.max_depth', 0),
            'rate_limit_ms' => Config::int('crawl.default_delay_ms', 1000),
            'respect_robots_txt' => Config::bool('crawl.respect_robots', true),
            'request_timeout' => Config::int('crawl.request_timeout', 60),
            'save_html' => Config::bool('crawl.save_html', true),
            // Berkas dokumen mentah (pdf/docx/xlsx) dihapus setelah vektornya
            // terkirim ke Qdrant; boleh ditimpa per entri sites.json
            // ("delete_documents_after_push": false) bila berkasnya ingin
            // disimpan untuk keperluan lain.
            'delete_documents_after_push' => Config::bool('crawl.delete_documents_after_push', true),
            // Penemuan berkas dokumen OTOMATIS dari log jaringan browser (tautan
            // yang tidak muncul di HTML/DOM, mis. daftar PDF dari API situs);
            // boleh ditimpa per entri sites.json ("auto_documents": false).
            'auto_documents' => Config::bool('crawl.auto_documents', true),
            'chunk_size' => Config::int('chunk.size', 3000),
            'chunk_overlap' => Config::int('chunk.overlap', 300),
            'min_chunk_length' => Config::int('chunk.min_length', 50),
            // Pembersihan markdown hasil /parse (menu, ornament, footer) dan
            // batas markup tautan per chunk; keduanya boleh ditimpa per entri
            // pada config/sites.json ("clean_markdown", "max_link_ratio").
            'clean_markdown' => Config::bool('chunk.clean_markdown', true),
            'max_link_ratio' => (float) Config::get('chunk.max_link_ratio', 0.6),
            // Buang isi halaman yang identik/mirip dengan URL lain pada site yang
            // sama (ciri situs berbasis JavaScript): markdown serupa hanya
            // dikirim sekali ke /embed.
            'drop_duplicate_content' => Config::bool('chunk.drop_duplicate_content', true),
            'duplicate_similarity' => (float) Config::get('chunk.duplicate_similarity', 0.85),
            // Render halaman dengan browser headless bila isinya baru dibuat
            // JavaScript. Nilai ini menimpa/menambah setelan config/app.php per
            // entri config/sites.json ("js_render": false | "always" | {...}).
            'js_render' => [
                'mode' => HeadlessRenderer::modeValue(Config::get('crawl.js_render', 'auto')) ?? 'auto',
                'wait_ms' => Config::int('crawl.browser_wait_ms', 6000),
                'timeout_ms' => Config::int('crawl.browser_timeout_ms', 25000),
                'bin' => Config::string('crawl.browser_bin'),
                'flags' => Config::string('crawl.browser_flags'),
                'user_agent' => Config::string('crawl.browser_user_agent'),
                'min_text' => Config::int('crawl.render_min_text', 2000),
                'ratio' => (float) Config::get('crawl.render_max_text_ratio', 0.03),
            ],
            'embed_batch_size' => Config::int('embed.batch_size', 8),
            'user_agent' => Config::string('crawl.user_agent'),
        ], $defaults);
    }

    /**
     * Semua target pada berkas konfigurasi.
     *
     * @return list<SiteConfig>
     */
    public function all(): array
    {
        if ($this->sites !== null) {
            return $this->sites;
        }

        $entries = self::entries(Config::sitesRaw());
        $defaults = $this->defaults();

        // ID yang sudah dipakai entri lain (agar id otomatis tidak bertabrakan:
        // folder storage, log per site, dan --site= memakai id ini).
        $used = [];
        foreach ($entries as $entry) {
            if (is_array($entry) && isset($entry['id']) && trim((string) $entry['id']) !== '') {
                $used[Text::slug((string) $entry['id'])] = true;
            }
        }

        $sites = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                // Baris catatan (string/angka) pada JSON diabaikan.
                continue;
            }

            $urls = self::entryUrls($entry);
            if ($urls === []) {
                continue;
            }

            if (!isset($entry['id']) || trim((string) $entry['id']) === '') {
                if (isset($entry['name']) && trim((string) $entry['name']) !== '') {
                    $entry['id'] = Text::slug((string) $entry['name']);
                } else {
                    $entry['id'] = self::uniqueId($urls[0], $used);
                }
            }

            $site = SiteConfig::fromArray($entry, $defaults);
            $used[$site->id] = true;
            $sites[] = $site;
        }

        $this->sites = $sites;

        return $sites;
    }

    /**
     * Daftar entri mentah pada config/sites.json: array di level teratas
     * (format dokumen) atau isi "sites"/"documents"/"targets"/"pages"
     * (format objek dengan "defaults").
     *
     * @param array<string, mixed> $raw
     *
     * @return list<mixed>
     */
    public static function entries(array $raw): array
    {
        if (array_is_list($raw)) {
            return $raw;
        }

        foreach (['sites', 'documents', 'targets', 'pages'] as $key) {
            if (isset($raw[$key]) && is_array($raw[$key])) {
                return array_values($raw[$key]);
            }
        }

        return [];
    }

    /**
     * URL pada satu entri ("url", "start_urls", "startUrls", atau "urls").
     *
     * @param array<string, mixed> $entry
     *
     * @return list<string>
     */
    public static function entryUrls(array $entry): array
    {
        $raw = $entry['start_urls'] ?? $entry['startUrls'] ?? $entry['urls'] ?? $entry['url'] ?? [];
        if (is_string($raw)) {
            $raw = [$raw];
        }

        $urls = [];
        foreach ((array) $raw as $url) {
            $url = trim((string) $url);
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * ID otomatis dari URL: host, lalu host + segmen path terakhir bila host
     * itu sudah dipakai, lalu akhiran angka sebagai jalan terakhir.
     *
     * @param array<string, bool> $used
     */
    private static function uniqueId(string $url, array $used): string
    {
        $base = SiteConfig::idForUrl($url);
        if (!isset($used[$base])) {
            return $base;
        }

        $withPath = SiteConfig::idForUrl($url, true);
        if (!isset($used[$withPath])) {
            return $withPath;
        }

        $counter = 2;
        while (isset($used[$base . '-' . $counter])) {
            $counter++;
        }

        return $base . '-' . $counter;
    }

    /**
     * @return list<SiteConfig>
     */
    public function enabled(): array
    {
        return array_values(array_filter($this->all(), static fn (SiteConfig $site): bool => $site->enabled));
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_map(static fn (SiteConfig $site): string => $site->id, $this->all());
    }

    /**
     * Cari site berdasarkan id (case-insensitive, nama/alias juga dicek).
     */
    public function find(string $id): ?SiteConfig
    {
        $needle = strtolower(trim($id));

        foreach ($this->all() as $site) {
            if (strtolower($site->id) === $needle || strtolower($site->name) === $needle) {
                return $site;
            }
        }

        return null;
    }

    /**
     * Ambil beberapa site sekaligus (dipakai parameter --site=a,b).
     *
     * @param list<string> $ids
     *
     * @return list<SiteConfig>
     */
    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return $this->enabled();
        }

        $result = [];
        $missing = [];

        foreach ($ids as $id) {
            $id = trim($id);
            if ($id === '') {
                continue;
            }

            $site = $this->find($id);
            if ($site === null) {
                $missing[] = $id;
                continue;
            }

            $result[] = $site;
        }

        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'Site tidak dikenal: %s. Site tersedia: %s',
                implode(', ', $missing),
                implode(', ', $this->ids())
            ));
        }

        return $result;
    }
}
