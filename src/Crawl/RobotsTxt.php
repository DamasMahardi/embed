<?php

declare(strict_types=1);

namespace App\Crawl;

use App\Http\HttpClient;
use App\Support\Text;

/**
 * Pemeriksa robots.txt sederhana (spesifikasi dasar):
 *  - aturan untuk "User-agent: *" dan untuk token UA crawler dipakai
 *  - grup UA spesifik diutamakan bila ada
 *  - bila Allow dan Disallow sama-sama cocok, pola terpanjang menang
 *  - robots.txt tidak ada (404/410) atau tidak bisa diunduh => URL dianggap
 *    bebas, tetapi kejadiannya tetap dicatat pada log
 */
final class RobotsTxt
{
    /** @var list<array{allow: bool, path: string}> */
    private array $rules = [];

    /**
     * @param list<array{allow: bool, path: string}> $rules
     * @param string $remoteIp     alamat IP yang melayani robots.txt (kosong bila
     *                             tidak diunduh / pemeriksaan dimatikan)
     * @param bool   $addressRetry true bila robots.txt baru berhasil setelah
     *                             dicoba ulang ke alamat IP lain (lihat
     *                             HttpClient::retryByAddress()) -- dipakai log
     *                             ADDR_RETRY supaya sebab lambatnya unduhan
     *                             pertama terlihat walaupun robots.txt ini
     *                             permintaan pertama ke host tersebut
     */
    private function __construct(
        public readonly bool $found,
        public readonly int $httpStatus,
        public readonly ?string $error,
        public readonly string $url,
        array $rules = [],
        public readonly ?float $crawlDelaySeconds = null,
        public readonly string $remoteIp = '',
        public readonly bool $addressRetry = false,
    ) {
        $this->rules = $rules;
    }

    public static function missing(
        string $url,
        int $httpStatus,
        ?string $error = null,
        string $remoteIp = '',
        bool $addressRetry = false,
    ): self {
        return new self(false, $httpStatus, $error, $url, [], null, $remoteIp, $addressRetry);
    }

    /**
     * Unduh dan uraikan robots.txt untuk host yang dipakai oleh site.
     */
    public static function load(HttpClient $http, SiteConfig $site, string $userAgent): self
    {
        $host = $site->hosts()[0] ?? '';
        if ($host === '') {
            return self::missing('', 0, 'site tidak punya start_url');
        }

        $scheme = 'https';
        foreach ($site->startUrls as $startUrl) {
            $parsed = parse_url($startUrl, PHP_URL_SCHEME);
            if (is_string($parsed) && $parsed !== '') {
                $scheme = strtolower($parsed);
                break;
            }
        }

        return self::loadFor($http, $host, $scheme, $userAgent);
    }

    /**
     * Unduh dan uraikan robots.txt untuk HOST tertentu.
     *
     * Dipakai juga untuk host BERKAS DOKUMEN yang berbeda dari host situs (mis.
     * backend.kemendagri.go.id pada aturan "document_api"), supaya aturan robots
     * host berkas tetap dihormati walaupun halaman situsnya tidak menautkan
     * berkas itu.
     */
    public static function loadFor(HttpClient $http, string $host, string $scheme, string $userAgent): self
    {
        $host = trim($host);
        if ($host === '') {
            return self::missing('', 0, 'host tidak diketahui');
        }

        $scheme = strtolower(trim($scheme));
        if ($scheme !== 'http') {
            $scheme = 'https';
        }

        $url = $scheme . '://' . $host . '/robots.txt';
        $response = $http->get($url, ['timeout' => 20, 'follow' => true, 'max_bytes' => 512_000]);

        if ($response->status === 404 || $response->status === 410) {
            return new self(false, $response->status, null, $url, [], null, $response->remoteIp, $response->addressRetry);
        }

        if (!$response->ok()) {
            return self::missing($url, $response->status, $response->errorMessage(160), $response->remoteIp, $response->addressRetry);
        }

        $parsed = self::parse($response->body, self::uaTokens($userAgent));

        return new self(
            true,
            $response->status,
            null,
            $url,
            $parsed['rules'],
            $parsed['delay'],
            $response->remoteIp,
            $response->addressRetry,
        );
    }

    /**
     * Cek apakah sebuah URL boleh diambil menurut robots.txt.
     */
    public function allows(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);
        $target = ($path === null || $path === '' ? '/' : $path)
            . ($query !== null && $query !== '' ? '?' . $query : '');

        $best = null;
        $bestLength = -1;

        foreach ($this->rules as $rule) {
            if ($rule['path'] === '' || !str_starts_with($target, $rule['path'])) {
                continue;
            }

            $length = strlen($rule['path']);
            if ($length > $bestLength) {
                $bestLength = $length;
                $best = $rule['allow'];
            }
        }

        return $best ?? true;
    }

    /**
     * Ringkasan untuk log txt.
     */
    public function summary(): string
    {
        if (!$this->found) {
            return $this->error !== null
                ? 'tidak tersedia (http=' . $this->httpStatus . ', ' . $this->error . ') => dianggap bebas'
                : 'tidak ada (http=' . $this->httpStatus . ') => dianggap bebas';
        }

        return 'ditemukan http=' . $this->httpStatus . ' aturan_dipakai=' . count($this->rules)
            . ($this->crawlDelaySeconds !== null ? ' crawl_delay=' . $this->crawlDelaySeconds . 's' : '');
    }

    public function ruleCount(): int
    {
        return count($this->rules);
    }

    /**
     * Jeda minimal antar request (milidetik): gabungan pengaturan site dan
     * Crawl-delay pada robots.txt, dipilih yang paling longgar.
     */
    public function effectiveDelayMs(int $configuredMs): int
    {
        $fromRobots = $this->crawlDelaySeconds === null ? 0 : (int) round($this->crawlDelaySeconds * 1000);

        return max($configuredMs, $fromRobots);
    }

    public function hostLabel(): string
    {
        return Text::hostname($this->url);
    }

    /**
     * Token UA yang dianggap milik crawler ini: nama produk (sebelum '/'),
     * produk lengkap dengan versi, dan nama bawaan aplikasi.
     *
     * @return list<string>
     */
    private static function uaTokens(string $userAgent): array
    {
        $lower = strtolower(trim($userAgent));
        $tokens = ['crawler-embed'];

        if ($lower === '') {
            return $tokens;
        }

        $first = trim(explode(' ', $lower)[0]);
        if ($first !== '') {
            $tokens[] = $first;
            $tokens[] = explode('/', $first)[0];
        }

        return array_values(array_unique(array_filter($tokens, static fn (string $t): bool => $t !== '')));
    }

    /**
     * @param list<string> $tokens
     */
    private static function matchesTokens(string $agent, array $tokens): bool
    {
        if ($agent === '' || str_contains($agent, '*')) {
            return false;
        }

        foreach ($tokens as $token) {
            if ($token !== '' && (str_contains($agent, $token) || str_contains($token, $agent))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Uraikan isi robots.txt. Aturan untuk token UA spesifik menang atas "*".
     *
     * @param list<string> $tokens
     *
     * @return array{rules: list<array{allow: bool, path: string}>, delay: ?float}
     */
    private static function parse(string $body, array $tokens): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];

        $star = ['rules' => [], 'delay' => null];
        $specific = ['rules' => [], 'delay' => null];
        $current = null; // 'star' | 'specific' | null

        foreach ($lines as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);
            $key = strtolower(trim($key));
            $value = trim($value);

            if ($key === 'user-agent') {
                $agent = strtolower($value);
                if ($agent === '*') {
                    $current = 'star';
                } elseif (self::matchesTokens($agent, $tokens)) {
                    $current = 'specific';
                } else {
                    $current = null;
                }

                continue;
            }

            if ($current === null) {
                continue;
            }

            if ($key === 'disallow' || $key === 'allow') {
                // "Disallow:" kosong berarti semua diizinkan => tidak perlu aturan.
                if ($value === '' && $key === 'disallow') {
                    continue;
                }

                if ($current === 'star') {
                    $star['rules'][] = ['allow' => $key === 'allow', 'path' => $value];
                } else {
                    $specific['rules'][] = ['allow' => $key === 'allow', 'path' => $value];
                }

                continue;
            }

            if ($key === 'crawl-delay' && is_numeric($value)) {
                if ($current === 'star') {
                    $star['delay'] = (float) $value;
                } else {
                    $specific['delay'] = (float) $value;
                }
            }
        }

        if ($specific['rules'] !== []) {
            return ['rules' => $specific['rules'], 'delay' => $specific['delay'] ?? $star['delay']];
        }

        return ['rules' => $star['rules'], 'delay' => $star['delay']];
    }
}
