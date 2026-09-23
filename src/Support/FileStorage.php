<?php

declare(strict_types=1);

namespace App\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Policy akses berkas yang boleh ditampilkan atau dihapus oleh UI lokal.
 */
final class FileStorage
{
    /** @var list<string> */
    private array $roots;

    /**
     * @param list<string> $roots
     */
    public function __construct(array $roots)
    {
        $this->roots = array_values(array_filter(array_map(
            static fn (string $root): string => rtrim(str_replace('\\', '/', $root), '/'),
            $roots,
        )));
    }

    public function resolve(string $relative): ?string
    {
        $relative = str_replace('\\', '/', $relative);
        if (str_contains($relative, '..')) {
            return null;
        }

        $absolute = realpath(base_path() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        if ($absolute === false || !is_file($absolute)) {
            return null;
        }

        $normalized = str_replace('\\', '/', $absolute);
        foreach ($this->roots as $root) {
            if (str_starts_with($normalized, $root . '/')) {
                return $normalized;
            }
        }

        return null;
    }

    public function delete(string $relative): string
    {
        $path = $this->resolve($relative);
        if ($path === null) {
            throw new RuntimeException('Berkas tidak ditemukan atau tidak diizinkan untuk dihapus.');
        }

        if (!@unlink($path)) {
            throw new RuntimeException('Gagal menghapus ' . basename($path) . '.');
        }

        return basename($path);
    }

    /**
     * Hapus .jsonl dan file pendamping run yang berada pada folder yang sama.
     *
     * @return int jumlah file yang terhapus
     */
    public function deleteVectorRun(string $relativeJsonl): int
    {
        $jsonl = $this->resolve($relativeJsonl);
        if ($jsonl === null || !str_ends_with(strtolower($jsonl), '.jsonl')) {
            throw new RuntimeException('Run vector tidak ditemukan atau tidak diizinkan untuk dihapus.');
        }

        $base = preg_replace('/\.jsonl$/i', '', $jsonl);
        $deleted = 0;
        foreach ([$jsonl, $base . '.qdrant.json', $base . '.manifest.json'] as $path) {
            if (is_file($path) && @unlink($path)) {
                $deleted++;
            }
        }

        if ($deleted === 0) {
            throw new RuntimeException('Tidak ada berkas run yang berhasil dihapus.');
        }

        return $deleted;
    }

    /**
     * Folder yang boleh dibersihkan (realpath) atau null bila di luar area.
     */
    public function allowedDir(string $dir): ?string
    {
        $absolute = realpath($dir);

        if ($absolute === false || !is_dir($absolute)) {
            return null;
        }

        $normalized = str_replace('\\', '/', $absolute);

        foreach ($this->roots as $root) {
            if ($normalized === $root || str_starts_with($normalized, $root . '/')) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * Hitung berkas pada folder yang diizinkan TANPA menghapus apa pun
     * (dipakai UI untuk menampilkan "N berkas (X MB) akan dihapus").
     *
     * @param list<string> $patterns pola nama berkas (fnmatch, mis. ['*.txt'])
     *
     * @return array{berkas: int, byte: int}
     */
    public function scanDir(string $dir, array $patterns = ['*'], bool $recursive = false): array
    {
        $total = 0;
        $byte = 0;

        foreach ($this->collectDir($dir, $patterns, $recursive) as $path) {
            $total++;
            $byte += max(0, (int) @filesize($path));
        }

        return ['berkas' => $total, 'byte' => $byte];
    }

    /**
     * Hapus SEMUA berkas pada folder yang diizinkan (mis. seluruh log harian,
     * seluruh riwayat job, atau seluruh berkas vektor) supaya tidak perlu
     * menghapus satu per satu dari UI.
     *
     * Hanya berkas di dalam folder yang termasuk area yang diizinkan yang
     * disentuh; folder itu sendiri tidak pernah dihapus dan subfolder yang jadi
     * kosong dibuang agar storage tetap rapi.
     *
     * @param list<string> $patterns pola nama berkas (fnmatch, mis. ['*.txt'])
     *
     * @return array{berkas: int, byte: int, gagal: int}
     */
    public function purgeDir(string $dir, array $patterns = ['*'], bool $recursive = false): array
    {
        $root = $this->allowedDir($dir);

        if ($root === null) {
            throw new RuntimeException('Folder tidak ditemukan atau tidak diizinkan untuk dibersihkan.');
        }

        $berkas = 0;
        $byte = 0;
        $gagal = 0;

        foreach ($this->collectDir($root, $patterns, $recursive) as $path) {
            $ukuran = max(0, (int) @filesize($path));

            if (@unlink($path)) {
                $berkas++;
                $byte += $ukuran;

                continue;
            }

            // Berkas bisa sedang dibuka proses lain (mis. log run yang sedang
            // ditulis): dicatat sebagai gagal, bukan menggagalkan semuanya.
            $gagal++;
        }

        if ($recursive) {
            self::removeEmptyDirs($root);
        }

        return ['berkas' => $berkas, 'byte' => $byte, 'gagal' => $gagal];
    }

    /**
     * Daftar berkas pada folder yang diizinkan sesuai pola.
     *
     * @param list<string> $patterns
     *
     * @return list<string>
     */
    private function collectDir(string $dir, array $patterns, bool $recursive): array
    {
        $root = $this->allowedDir($dir);

        if ($root === null) {
            return [];
        }

        $patterns = array_values(array_filter(array_map('strval', $patterns)));
        if ($patterns === []) {
            $patterns = ['*'];
        }

        $cocok = static function (string $path) use ($patterns): bool {
            $nama = basename($path);

            foreach ($patterns as $pattern) {
                if (fnmatch($pattern, $nama)) {
                    return true;
                }
            }

            return false;
        };

        $files = [];

        if (!$recursive) {
            foreach (glob($root . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
                if (is_file($path) && $cocok($path)) {
                    $files[] = str_replace('\\', '/', $path);
                }
            }

            return $files;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $item) {
            if ($item instanceof SplFileInfo && $item->isFile() && $cocok($item->getPathname())) {
                $files[] = str_replace('\\', '/', $item->getPathname());
            }
        }

        return $files;
    }

    /**
     * Buang subfolder yang sudah kosong (folder akar dipertahankan).
     */
    private static function removeEmptyDirs(string $root): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item instanceof SplFileInfo && $item->isDir()) {
                @rmdir($item->getPathname());
            }
        }
    }
}
