<?php

declare(strict_types=1);

namespace App\Crawl;

/**
 * Ekstraksi & normalisasi tautan dari HTML (untuk crawling berantai).
 */
final class LinkExtractor
{
    /** Parameter pelacak yang dibuang agar URL tidak dianggap unik. */
    private const TRACKING_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'gclid', 'fbclid', 'yclid', 'igshid', 'mc_cid', 'mc_eid', '_gl',
    ];

    /**
     * Tag + atribut sumber daya yang ikut dipindai selain <a href>/<area href>.
     *
     * Pratinjau dokumen (PDF) sering ditulis sebagai <object data="...pdf"> atau
     * <embed src="...pdf">, kadang tanpa tautan <a> sama sekali; media memakai
     * <source src="...">. URL-nya tetap melewati skip_extensions sehingga aset
     * gambar/video/arsip tidak ikut di-crawl.
     */
    private const EMBEDDED_SOURCES = [
        'object' => 'data',
        'embed' => 'src',
        'iframe' => 'src',
        'source' => 'src',
    ];

    /**
     * @param list<string> $skipExtensions
     *
     * @return list<string> URL absolut, unik, urut sesuai kemunculan
     */
    public static function extract(string $html, string $baseUrl, array $skipExtensions = []): array
    {
        if (trim($html) === '') {
            return [];
        }

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);

        // Bungkus dengan meta charset agar teks non-ASCII tidak rusak saat
        // DOMDocument menebak encoding.
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            return [];
        }

        // Hormati <base href="..."> bila ada (perilaku sama seperti browser).
        $documentBase = $baseUrl;
        foreach ($dom->getElementsByTagName('base') as $baseNode) {
            $href = trim($baseNode->getAttribute('href'));
            $resolved = $href === '' ? null : self::normalizeUrl($href, $baseUrl);

            if ($resolved !== null) {
                $documentBase = $resolved;
                break;
            }
        }

        $found = [];
        $seen = [];

        foreach (['a', 'area'] as $tag) {
            foreach ($dom->getElementsByTagName($tag) as $node) {
                $href = trim($node->getAttribute('href'));
                if ($href === '') {
                    continue;
                }

                $url = self::normalizeUrl($href, $documentBase);
                if ($url === null || isset($seen[$url])) {
                    continue;
                }

                if ($skipExtensions !== [] && self::hasSkippedExtension($url, $skipExtensions)) {
                    continue;
                }

                $seen[$url] = true;
                $found[] = $url;
            }
        }

        // Tautan yang TIDAK ditulis sebagai <a href> tetapi tetap menunjuk
        // berkas: pratinjau PDF memakai <object data="...pdf">/<embed src="...">
        // dan pemutar media memakai <source src="...">. Tanpa pemindaian ini
        // URL berkasnya tidak pernah masuk antrean sehingga isi PDF tidak ikut
        // dibaca (halaman hanya menghasilkan markdown menu/UI).
        foreach (self::EMBEDDED_SOURCES as $tag => $attribute) {
            foreach ($dom->getElementsByTagName($tag) as $node) {
                $value = trim($node->getAttribute($attribute));
                if ($value === '') {
                    continue;
                }

                $url = self::normalizeUrl($value, $documentBase);
                if ($url === null || isset($seen[$url])) {
                    continue;
                }

                if ($skipExtensions !== [] && self::hasSkippedExtension($url, $skipExtensions)) {
                    continue;
                }

                $seen[$url] = true;
                $found[] = $url;
            }
        }

        return $found;
    }

    /**
     * Ubah href apa pun menjadi URL absolut yang bersih, atau null bila tidak
     * relevan (mailto:, javascript:, data:, dsb).
     */
    public static function normalizeUrl(string $href, string $baseUrl): ?string
    {
        $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $href = str_replace(["\n", "\r", "\t", ' '], '', $href);

        if ($href === '' || str_starts_with($href, '#')) {
            return null;
        }

        if (preg_match('#^(mailto|tel|javascript|data|blob|file|ftp|whatsapp):#i', $href) === 1) {
            return null;
        }

        $absolute = self::absolutize($href, $baseUrl);
        if ($absolute === null) {
            return null;
        }

        $parts = parse_url($absolute);
        if (!is_array($parts) || !isset($parts['host']) || $parts['host'] === '') {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $host = strtolower($parts['host']);
        $port = isset($parts['port']) && !in_array((int) $parts['port'], [80, 443], true)
            ? ':' . $parts['port']
            : '';

        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }

        $path = preg_replace('#/\./#', '/', $path) ?? $path;
        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;

        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $scheme . '://' . $host . $port . $path . self::cleanQuery((string) ($parts['query'] ?? ''));
    }

    /**
     * @param list<string> $skipExtensions
     */
    private static function hasSkippedExtension(string $url, array $skipExtensions): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $extension !== '' && in_array($extension, $skipExtensions, true);
    }

    private static function absolutize(string $href, string $baseUrl): ?string
    {
        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }

        if (str_starts_with($href, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME);

            return (is_string($scheme) && $scheme !== '' ? $scheme : 'https') . ':' . $href;
        }

        $base = parse_url($baseUrl);
        if (!is_array($base) || !isset($base['host'])) {
            return null;
        }

        $scheme = (string) ($base['scheme'] ?? 'https');
        $origin = $scheme . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');

        if (str_starts_with($href, '?')) {
            return $origin . (string) ($base['path'] ?? '/') . $href;
        }

        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }

        $basePath = (string) ($base['path'] ?? '/');
        $slash = strrpos($basePath, '/');
        $directory = $slash === false ? '/' : substr($basePath, 0, $slash + 1);

        return $origin . $directory . $href;
    }

    /**
     * Buang parameter pelacak dan normalisasi urutan agar duplikat terdeteksi.
     */
    private static function cleanQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        parse_str($query, $params);
        foreach (self::TRACKING_PARAMS as $key) {
            unset($params[$key]);
        }

        if ($params === []) {
            return '';
        }

        ksort($params);

        return '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}
