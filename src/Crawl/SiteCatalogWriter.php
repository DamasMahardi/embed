<?php

declare(strict_types=1);

namespace App\Crawl;

use App\Support\Config;
use RuntimeException;

/**
 * Menulis target crawl ke config/sites.json tanpa membocorkan format storage
 * ke controller HTTP.
 */
final class SiteCatalogWriter
{
    public function __construct(private string $path)
    {
    }

    /**
     * Tambahkan target ke format flat atau format {defaults, sites} yang ada.
     *
     * @param list<string> $urls
     * @param array<string, mixed> $metadata
     * @param list<string> $excludePatterns
     * @return array{id: string, added: int}
     */
    public function add(
        string $id,
        string $name,
        array $urls,
        array $metadata = [],
        array $excludePatterns = [],
        bool $enabled = true,
    ): array {
        $raw = Config::sitesRaw();
        $repository = new SiteRepository();

        foreach ($repository->all() as $existing) {
            if (strtolower($existing->id) === strtolower($id)) {
                throw new RuntimeException('ID site sudah digunakan: ' . $id);
            }
        }

        $entries = SiteRepository::entries($raw);
        $isFlat = array_is_list($raw);
        $firstHost = strtolower((string) parse_url($urls[0], PHP_URL_HOST));

        if ($isFlat) {
            foreach ($urls as $url) {
                $entry = [
                    'url' => $url,
                    'documentType' => (string) ($metadata['document_type'] ?? ''),
                    'province' => (string) ($metadata['province'] ?? ''),
                    'city' => (string) ($metadata['city'] ?? ''),
                    'year' => $metadata['year'] ?? '',
                ];

                if ($name !== '') {
                    $entry['name'] = $name;
                }

                if (!$enabled) {
                    $entry['enabled'] = false;
                }

                $raw[] = $entry;
            }
        } else {
            $site = [
                'id' => $id,
                'enabled' => $enabled,
                'start_urls' => $urls,
                'include_patterns' => ['^https?://' . preg_quote($firstHost, '#') . '/'],
            ];

            if ($name !== '') {
                $site['name'] = $name;
            }

            if ($excludePatterns !== []) {
                $site['exclude_patterns'] = $excludePatterns;
            }

            if ($metadata !== []) {
                $site['metadata'] = $metadata;
            }

            $raw['sites'] = array_values(array_merge($entries, [$site]));
        }

        $json = json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Gagal mengencode config/sites.json.');
        }

        $temporary = $this->path . '.tmp';
        if (@file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false || !@rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new RuntimeException('Gagal menyimpan config/sites.json.');
        }

        return ['id' => $id, 'added' => count($urls)];
    }
}
