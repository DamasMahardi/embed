<?php

declare(strict_types=1);

namespace App\Support;

/**
 * UUID generator tanpa dependency.
 *
 * UUID v5 dipakai untuk document_id (deterministik dari URL) agar re-crawl URL
 * yang sama menimpa dokumen lama, bukan membuat duplikat. Namespace-nya
 * sengaja sama dengan crawl-web/src/chunker.ts supaya identitas dokumen
 * konsisten antar pipeline.
 */
final class Uuid
{
    public const DOCUMENT_NAMESPACE = '6f2c6b0a-3b0a-4c0e-9c1a-1f6a2b6f9d10';

    public static function v5(string $name, string $namespace = self::DOCUMENT_NAMESPACE): string
    {
        $namespaceHex = str_replace('-', '', strtolower($namespace));

        if (strlen($namespaceHex) !== 32 || !ctype_xdigit($namespaceHex)) {
            throw new \InvalidArgumentException('Namespace UUID tidak valid: ' . $namespace);
        }

        $hash = sha1(hex2bin($namespaceHex) . $name, true);

        // Ambil 16 byte pertama.
        $bytes = substr($hash, 0, 16);

        // Set versi (5) dan varian (RFC 4122).
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x50);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return self::format($bytes);
    }

    public static function v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return self::format($bytes);
    }

    private static function format(string $bytes): string
    {
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
