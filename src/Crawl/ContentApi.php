<?php

declare(strict_types=1);

namespace App\Crawl;

use App\Http\HttpClient;
use App\Support\Text;

/**
 * Pengambil isi halaman dari API resmi situs (aturan "content_api" pada
 * config/sites.json).
 *
 * Situs modern berbasis JavaScript (mis. Next.js App Router) mengirim HTML
 * kerangka saat diunduh tanpa menjalankan JavaScript: menu, tagline, dan footer
 * sudah ada, tetapi isi halaman baru diminta browser lewat API. Akibatnya
 * tahap /parse hanya melihat boilerplate dan dokumen yang tersimpan berisi menu
 * yang sama berulang kali. Aturan "content_api" memberi tahu crawler endpoint
 * JSON yang dipakai halaman itu beserta field yang berisi isi halaman, sehingga
 * markdown dokumen diambil dari isi aslinya.
 *
 * Bentuk satu aturan (hanya "url" yang wajib):
 *   {
 *     "match": "^https?://(?:www\\.)?contoh\\.go\\.id/profil/",  // regex URL halaman, kosong = semua URL
 *     "url": "https://api.contoh.go.id/v1/pages/public/slug/{1}", // template endpoint
 *     "field": "data.pages_desc",       // jalur field isi halaman ("" = seluruh badan respons)
 *     "title_field": "data.pages_name", // jalur field judul (opsional)
 *     "format": "html",                 // "html" (bawaan, dikonversi ke markdown) atau "text"
 *     "min_length": 200,                // isi lebih pendek dari ini dianggap gagal
 *     "timeout": 20                     // detik; 0 = request_timeout site
 *   }
 *
 * Template "url" mendukung placeholder {slug} (segmen terakhir path halaman),
 * {path}, {host}, {query}, {url}, dan {1}..{n} (grup tangkap regex "match").
 *
 * Semua kegagalan (URL tidak cocok, HTTP bukan 2xx, respons bukan JSON, field
 * kosong, isi terlalu pendek) hanya menghasilkan null: pemanggil kembali ke
 * jalur /parse seperti biasa dan kejadiannya dicatat pada log. Aturan yang
 * belum tepat karena itu tidak menghilangkan halaman dari hasil crawl.
 */
final class ContentApi
{
    /** Batas ukuran respons API yang dibaca (cukup untuk metadata + isi halaman). */
    public const MAX_BYTES = 4_194_304;

    public function __construct(
        private readonly HttpClient $http,
        private readonly HtmlToMarkdown $html,
    ) {
    }

    public function hasRules(SiteConfig $site): bool
    {
        return $site->contentApi !== [];
    }

    /**
     * Aturan pertama yang cocok untuk URL halaman ini.
     *
     * @return array<string, mixed>|null
     */
    public function ruleFor(SiteConfig $site, string $url): ?array
    {
        foreach ($site->contentApi as $rule) {
            $match = (string) ($rule['match'] ?? '');

            if ($match === '') {
                return $rule;
            }

            $groups = [];
            if (@preg_match('#' . $match . '#i', $url, $groups) === 1) {
                $rule['_groups'] = $groups;

                return $rule;
            }
        }

        return null;
    }

    /**
     * Ambil isi halaman dari endpoint aturan ini.
     *
     * @param array<string, mixed> $rule aturan ternormalisasi (boleh berisi "_groups")
     *
     * @return array{markdown: string, title: string, endpoint: string, http: int, field: string}|null
     */
    public function fetch(array $rule, string $url, SiteConfig $site, ?string &$error = null): ?array
    {
        $error = null;

        $endpoint = self::endpoint((string) ($rule['url'] ?? ''), $url, (array) ($rule['_groups'] ?? []));
        if ($endpoint === '') {
            $error = 'template url aturan tidak valid';

            return null;
        }

        $configured = (int) ($rule['timeout'] ?? 0);
        $response = $this->http->get($endpoint, [
            'timeout' => $configured > 0 ? max(5, $configured) : max(5, $site->requestTimeout),
            'max_bytes' => self::MAX_BYTES,
            'headers' => ['Accept' => 'application/json, text/plain;q=0.9, */*;q=0.5'],
        ]);

        if (!$response->ok()) {
            $error = 'HTTP ' . $response->status . ($response->error !== null ? ' (' . $response->error . ')' : '');

            return null;
        }

        $field = trim((string) ($rule['field'] ?? ''));
        $json = null;

        if ($field === '') {
            $value = $response->body;
        } else {
            $json = $response->json();
            if ($json === null) {
                $error = 'respons bukan JSON';

                return null;
            }

            $value = self::dig($json, $field);
        }

        $raw = self::flatten($value);
        if ($raw === '') {
            $error = 'field ' . ($field === '' ? '<badan>' : $field) . ' kosong';

            return null;
        }

        $title = '';
        $titleField = trim((string) ($rule['title_field'] ?? ''));
        if ($titleField !== '' && $json !== null) {
            $title = Text::normalizeWhitespace(self::flatten(self::dig($json, $titleField)));
        }

        if ((string) ($rule['format'] ?? 'html') === 'text') {
            $markdown = Text::normalizeWhitespace(
                html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            );
        } else {
            $converted = $this->html->extract($raw);
            $markdown = trim($converted['markdown']);

            if ($title === '') {
                $title = trim($converted['title']);
            }
        }

        $minimum = max(0, (int) ($rule['min_length'] ?? 0));
        if ($markdown === '' || mb_strlen($markdown) < $minimum) {
            $error = 'isi terlalu pendek (' . mb_strlen($markdown) . ' < ' . $minimum . ' karakter)';

            return null;
        }

        return [
            'markdown' => $markdown,
            'title' => Text::oneLine($title, 240),
            'endpoint' => $endpoint,
            'http' => $response->status,
            'field' => $field === '' ? '<badan>' : $field,
        ];
    }

    /**
     * Susun URL endpoint dari template aturan.
     *
     * Placeholder yang tidak dikenal membuat template dianggap tidak valid
     * (mengembalikan string kosong) supaya salah tulis config tidak diam-diam
     * memanggil URL yang salah.
     *
     * @param list<string> $groups hasil preg_match (indeks 0 = seluruh kecocokan)
     */
    public static function endpoint(string $template, string $url, array $groups = []): string
    {
        $template = trim($template);
        if (preg_match('#^https?://#i', $template) !== 1) {
            return '';
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $segments = array_values(array_filter(explode('/', $path), static fn (string $part): bool => $part !== ''));
        $last = $segments === [] ? '' : (string) end($segments);

        $map = [
            '{slug}' => rawurlencode((string) preg_replace('/\.[a-z0-9]{1,5}$/i', '', $last)),
            '{path}' => implode('/', array_map('rawurlencode', $segments)),
            '{host}' => Text::hostname($url),
            '{query}' => (string) (parse_url($url, PHP_URL_QUERY) ?? ''),
            '{url}' => $url,
        ];

        foreach ($groups as $index => $value) {
            if ($index === 0 || !is_string($value)) {
                continue;
            }

            $map['{' . $index . '}'] = rawurlencode($value);
        }

        $endpoint = strtr($template, $map);

        return preg_match('/\{[a-z0-9]+\}/i', $endpoint) === 1 ? '' : $endpoint;
    }

    /**
     * Ambil nilai dari struktur JSON memakai jalur bertitik ("data.pages_desc").
     */
    public static function dig(mixed $data, string $path): mixed
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        $current = $data;
        foreach (explode('.', $path) as $key) {
            $key = trim($key);
            if ($key === '') {
                continue;
            }

            if (is_array($current) && array_key_exists($key, $current)) {
                $current = $current[$key];
                continue;
            }

            return null;
        }

        return $current;
    }

    /**
     * Ubah nilai JSON (teks, angka, atau daftar) menjadi satu blok teks.
     */
    public static function flatten(mixed $value, int $depth = 0): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (!is_array($value) || $depth > 4) {
            return '';
        }

        $parts = [];
        foreach ($value as $item) {
            $text = self::flatten($item, $depth + 1);
            if ($text !== '') {
                $parts[] = $text;
            }

            if (count($parts) >= 200) {
                break;
            }
        }

        return trim(implode("\n\n", $parts));
    }
}
