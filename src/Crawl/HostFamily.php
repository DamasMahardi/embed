<?php

declare(strict_types=1);

namespace App\Crawl;

/**
 * Keluarga domain (registrable domain) untuk crawl lintas situs.
 *
 * Situs .go.id sering memecah isinya ke banyak subdomain: halaman
 * www.kemendagri.go.id menautkan otda.kemendagri.go.id,
 * polpum.kemendagri.go.id, dan seterusnya. Dengan mode "family" crawler
 * mengikuti tautan itu tetapi tetap membatasi diri pada satu keluarga domain
 * (kemendagri.go.id) supaya tidak menyeret situs lain di luar keluarga.
 *
 * Domain publik dua tingkat (go.id, co.id, ac.id, ...) ikut diperhitungkan
 * sehingga:
 *   otda.kemendagri.go.id     + polpum.kemendagri.go.id  -> satu keluarga
 *   www.kemendagri.go.id      + www.kemenkeu.go.id       -> BUKAN keluarga
 */
final class HostFamily
{
    /** Label kedua yang menandakan domain publik dua tingkat. */
    private const TWO_LEVEL_SUFFIX = ['co', 'go', 'ac', 'or', 'ne', 'net', 'sch', 'mil', 'gov', 'com', 'edu'];

    /**
     * Domain terdaftar sederhana dari sebuah host.
     */
    public static function registrableDomain(string $host): string
    {
        $parts = array_values(array_filter(
            explode('.', self::normalizeHost($host)),
            static fn (string $part): bool => $part !== ''
        ));
        $jumlah = count($parts);

        if ($jumlah <= 2) {
            return implode('.', $parts);
        }

        $kedua = $parts[$jumlah - 2];

        return in_array($kedua, self::TWO_LEVEL_SUFFIX, true)
            ? implode('.', array_slice($parts, -3))
            : implode('.', array_slice($parts, -2));
    }

    /**
     * Apakah dua host satu keluarga domain? (mis. otda.kemendagri.go.id dan
     * kemendagri.go.id -> ya).
     */
    public static function sameFamily(string $a, string $b): bool
    {
        $domainA = self::registrableDomain($a);
        $domainB = self::registrableDomain($b);

        return $domainA !== '' && $domainA === $domainB;
    }

    /**
     * Host berada dalam salah satu keluarga domain daftar $keluarga.
     *
     * @param list<string> $keluarga daftar host acuan (mis. host start_urls)
     */
    public static function inFamilies(array $keluarga, string $host): bool
    {
        $host = self::normalizeHost($host);

        if ($host === '') {
            return false;
        }

        $domain = self::registrableDomain($host);

        foreach ($keluarga as $acuan) {
            if (self::registrableDomain($acuan) === $domain) {
                return true;
            }
        }

        return false;
    }

    /**
     * Host polos dari nilai apa pun: "https://otda.kemendagri.go.id/x" atau
     * "OTDA.Kemendagri.Go.Id" -> "otda.kemendagri.go.id".
     */
    private static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));

        if (str_contains($host, '://')) {
            $parsed = parse_url($host, PHP_URL_HOST);

            return is_string($parsed) ? $parsed : '';
        }

        return $host;
    }
}
