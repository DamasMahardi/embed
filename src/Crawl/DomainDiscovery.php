<?php

declare(strict_types=1);

namespace App\Crawl;

use App\Http\HttpClient;
use App\Support\Text;

/**
 * Penemu WEBSITE dari satu NAMA DOMAIN (mis. "kemendagri.go.id").
 *
 * Dipakai sebelum crawl: pengguna memasukkan domain, lalu crawler mencari
 * subdomain/sub-situs yang masih satu keluarga (ppid.kemendagri.go.id,
 * otda.kemendagri.go.id, ...) lewat:
 *
 *   1. Certificate Transparency (crt.sh) -> daftar host yang pernah terbit
 *      sertifikat untuk *.<domain>;
 *   2. daftar awalan subdomain umum (ppid, jdih, opendata, data, sip, ...);
 *   3. host dasar + www.<domain>.
 *
 * Setiap kandidat lalu di-PROBE (HTTPS) untuk memastikan benar-benar aktif
 * (HTTP 2xx/3xx/401/403); yang tidak menjawab dibuang. Hasilnya ditampilkan di
 * dashboard dan ditulis ke config/sites.json sebagai daftar target crawl.
 */
final class DomainDiscovery
{
    /** Awalan subdomain umum (dicoba sebagai cadangan bila crt.sh tidak ada). */
    private const HEURISTIC_PREFIXES = [
        'www', 'ppid', 'jdih', 'opendata', 'data', 'sip', 'setjen', 'itjen',
        'satudata', 'satu-data', 'sakip', 'e-sakip', 'e-learning', 'portal',
        'layanan', 'informasi', 'berita', 'media', 'api', 'backend', 'otda',
        'polpum', 'bangda', 'bina-adwil', 'bina-pemdes', 'itbang', 'pusdiklat',
    ];

    public function __construct(private HttpClient $http)
    {
    }

    /**
     * Normalisasi input menjadi domain polos: buang skema, path, kueri, www.
     */
    public static function normalize(string $input): string
    {
        $input = trim($input);
        $input = str_contains($input, '://') ? $input : 'https://' . $input;

        $host = strtolower((string) (parse_url($input, PHP_URL_HOST) ?? ''));

        if ($host === '') {
            return '';
        }

        return preg_replace('/^www\./', '', $host) ?? $host;
    }

    /**
     * Jalankan penemuan: (host => status HTTP) untuk situs yang aktif.
     *
     * @return array{domain: string, situs: list<array{host: string, url: string, status: int}>, jumlah: int, kandidat: int}
     */
    public function discover(string $domain, int $maxHosts = 80): array
    {
        $domain = self::normalize($domain);

        if ($domain === '' || !str_contains($domain, '.')) {
            return ['domain' => $domain, 'situs' => [], 'jumlah' => 0, 'kandidat' => 0];
        }

        $hosts = $this->candidateHosts($domain, max(5, $maxHosts));
        $situs = [];

        foreach ($hosts as $host) {
            $probe = $this->probe($host);

            if ($probe !== null) {
                $situs[] = $probe;
            }
        }

        usort($situs, static fn (array $a, array $b): int => strcmp((string) $a['host'], (string) $b['host']));

        return ['domain' => $domain, 'situs' => $situs, 'jumlah' => count($situs), 'kandidat' => count($hosts)];
    }

    /**
     * Daftar kandidat host untuk satu domain (unik, dibatasi $maxHosts).
     *
     * @return list<string>
     */
    private function candidateHosts(string $domain, int $maxHosts): array
    {
        // Urutan penting: host dasar + subdomain "konten" umum didahulukan
        // supaya situs informasi (ppid/jdih/data/...) tidak kalah kuota dari
        // ratusan host teknis yang dikembalikan crt.sh.
        $hosts = [$domain, 'www.' . $domain];

        foreach (self::HEURISTIC_PREFIXES as $prefix) {
            $hosts[] = $prefix . '.' . $domain;
        }

        // Certificate Transparency (crt.sh) mengisi slot sisanya.
        try {
            $response = $this->http->get('https://crt.sh/?q=%25.' . rawurlencode($domain) . '&output=json', [
                'timeout' => 30,
                'headers' => ['Accept' => 'application/json', 'User-Agent' => 'crawler-embed/1.0'],
            ]);

            if ($response->status === 200) {
                foreach (self::parseCertEntries((string) $response->body, $domain) as $host) {
                    $hosts[] = $host;
                }
            }
        } catch (\Throwable) {
            // crt.sh kadang lambat/tidak terjangkau; kandidat cadangan tetap dipakai.
        }

        $unik = [];

        foreach ($hosts as $host) {
            $host = strtolower(trim($host));

            if ($host === '' || str_contains($host, '*') || !str_ends_with($host, '.' . $domain)) {
                continue;
            }

            $unik[$host] = true;

            if (count($unik) >= $maxHosts) {
                break;
            }
        }

        return array_keys($unik);
    }

    /**
     * Ekstrak hostname dari respons JSON crt.sh (field name_value dipisah \n).
     *
     * @return list<string>
     */
    public static function parseCertEntries(string $json, string $domain): array
    {
        $data = json_decode($json, true);

        if (!is_array($data)) {
            return [];
        }

        $hosts = [];

        foreach ($data as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            foreach (explode("\n", (string) ($entry['name_value'] ?? '')) as $bagian) {
                $host = strtolower(trim($bagian));
                $host = ltrim($host, '*.');

                if ($host === '' || str_contains($host, '*') || !str_ends_with($host, '.' . $domain)) {
                    continue;
                }

                $hosts[$host] = true;
            }

            $cn = strtolower(ltrim(trim((string) ($entry['common_name'] ?? '')), '*.'));

            if ($cn !== '' && !str_contains($cn, '*') && str_ends_with($cn, '.' . $domain)) {
                $hosts[$cn] = true;
            }
        }

        return array_keys($hosts);
    }

    /**
     * Probe satu host dengan HTTPS; null bila tidak aktif.
     *
     * @return array{host: string, url: string, status: int}|null
     */
    private function probe(string $host): ?array
    {
        $url = 'https://' . $host . '/';

        try {
            $response = $this->http->get($url, [
                'timeout' => 8,
                'headers' => ['Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8'],
            ]);

            if (in_array($response->status, [200, 201, 202, 204, 301, 302, 303, 307, 308, 401, 403], true)) {
                $efektif = $response->effectiveUrl !== '' ? $response->effectiveUrl : $url;
                $hostEfektif = Text::hostname($efektif);

                return [
                    'host' => ($hostEfektif !== '' && $hostEfektif !== $url) ? $hostEfektif : $host,
                    'url' => $efektif,
                    'status' => $response->status,
                ];
            }
        } catch (\Throwable) {
            // tidak aktif / timeout: dilewati.
        }

        return null;
    }
}
