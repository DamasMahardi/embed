<?php

declare(strict_types=1);

namespace App\Storage;

use PDO;
use RuntimeException;

/**
 * Penyimpan status crawl per WEBSITE ke MySQL lokal (Laragon).
 *
 * Dipakai untuk menghindari crawl duplikat: website yang SUDAH pernah
 * di-crawl dengan status "success" bisa dilewati otomatis, dan website yang
 * gagal diakses dicatat "failed_akses" supaya penyebabnya terlihat di
 * dashboard.
 *
 * Skema dibuat otomatis (CREATE DATABASE IF NOT EXISTS + CREATE TABLE IF NOT
 * EXISTS) pada pemakaian pertama, memakai setelan MYSQL_* pada .env:
 *   MYSQL_HOST=127.0.0.1  MYSQL_PORT=3306  MYSQL_DB=crawler_embed
 *   MYSQL_USER=root       MYSQL_PASS=
 */
final class CrawlSiteStore
{
    private ?PDO $pdo = null;

    /** @param array<string, mixed> $config bagian "mysql" pada config/app.php */
    public function __construct(private array $config)
    {
    }

    /**
     * Koneksi PDO + pastikan database & tabel tersedia.
     */
    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $host = (string) ($this->config['host'] ?? '127.0.0.1');
        $port = (int) ($this->config['port'] ?? 3306);
        $user = (string) ($this->config['user'] ?? 'root');
        $pass = (string) ($this->config['password'] ?? '');
        $db = (string) ($this->config['database'] ?? 'crawler_embed');

        try {
            $server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);

            $server->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $db) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

            $this->pdo = new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);

            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS crawl_sites (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    domain VARCHAR(190) NOT NULL,
                    url VARCHAR(512) NOT NULL,
                    document_type VARCHAR(100) DEFAULT NULL,
                    province VARCHAR(100) DEFAULT NULL,
                    city VARCHAR(100) DEFAULT NULL,
                    `year` VARCHAR(20) DEFAULT NULL,
                    status ENUM("success","failed_akses","discovered") NOT NULL DEFAULT "success",
                    message VARCHAR(255) NOT NULL DEFAULT "",
                    run_id VARCHAR(40) NOT NULL DEFAULT "",
                    last_crawled DATETIME NOT NULL,
                    UNIQUE KEY uq_url (url),
                    KEY idx_domain (domain),
                    KEY idx_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );

            // Tabel lama (bila sudah terlanjur dibuat) statusnya belum memuat
            // "discovered": ENUM-nya diperluas di sini.
            try {
                $this->pdo->exec(
                    'ALTER TABLE crawl_sites MODIFY status ENUM("success","failed_akses","discovered") NOT NULL DEFAULT "success"'
                );
            } catch (\Throwable) {
                // Sudah sesuai / tidak bisa diubah: lanjut saja.
            }

            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS discovery_logs (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    domain VARCHAR(190) NOT NULL,
                    jumlah_ditemukan INT NOT NULL DEFAULT 0,
                    jumlah_kandidat INT NOT NULL DEFAULT 0,
                    log MEDIUMTEXT NULL,
                    created_at DATETIME NOT NULL,
                    KEY idx_domain (domain),
                    KEY idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (\Throwable $exception) {
            throw new RuntimeException('MySQL tidak terjangkau: ' . $exception->getMessage());
        }

        return $this->pdo;
    }

    /**
     * Apakah URL ini pernah di-crawl SUKSES? (dipakai untuk melewati duplikat).
     */
    public function hasSuccess(string $url): bool
    {
        $statement = $this->pdo()->prepare(
            'SELECT 1 FROM crawl_sites WHERE url = ? AND status = "success" LIMIT 1'
        );
        $statement->execute([$url]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Catat/UPDATE status crawl satu website (upsert berdasarkan URL).
     *
     * @param array<string, mixed> $meta document_type, province, city, year
     */
    public function record(
        string $domain,
        string $url,
        array $meta,
        string $status,
        string $message,
        string $runId,
    ): void {
        $statement = $this->pdo()->prepare(
            'INSERT INTO crawl_sites
                (domain, url, document_type, province, city, `year`, status, message, run_id, last_crawled)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                message = VALUES(message),
                run_id = VALUES(run_id),
                last_crawled = NOW()'
        );
        $statement->execute([
            $domain,
            $url,
            self::nullable($meta['document_type'] ?? null),
            self::nullable($meta['province'] ?? null),
            self::nullable($meta['city'] ?? null),
            self::nullable($meta['year'] ?? null),
            in_array($status, ['success', 'failed_akses', 'discovered'], true) ? $status : 'success',
            mb_substr($message, 0, 250),
            $runId,
        ]);
    }

    /**
     * Daftar riwayat crawl (untuk panel dashboard).
     *
     * @return list<array<string, mixed>>
     */
    public function list(string $status = '', int $limit = 200): array
    {
        $sql = 'SELECT * FROM crawl_sites';
        $params = [];

        if ($status !== '') {
            $sql .= ' WHERE status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY last_crawled DESC, id DESC LIMIT ' . max(1, min(2000, $limit));

        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Ringkasan jumlah per status.
     *
     * @return array{success: int, failed_akses: int, discovered: int, total: int}
     */
    public function stats(): array
    {
        $statement = $this->pdo()->query('SELECT status, COUNT(*) AS jumlah FROM crawl_sites GROUP BY status');
        $stats = ['success' => 0, 'failed_akses' => 0, 'discovered' => 0, 'total' => 0];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $stats[(string) $row['status']] = (int) $row['jumlah'];
            $stats['total'] += (int) $row['jumlah'];
        }

        return $stats;
    }

    /**
     * Catat satu website HASIL PENEMUAN DOMAIN sebagai status "discovered".
     *
     * Memakai INSERT IGNORE supaya website yang SUDAH punya riwayat crawl
     * (success / failed_akses) TIDAK diturunkan statusnya menjadi "discovered".
     */
    public function recordDiscovered(
        string $domain,
        string $url,
        string $host,
        int $httpStatus,
        array $meta,
        string $message,
    ): void {
        $statement = $this->pdo()->prepare(
            'INSERT IGNORE INTO crawl_sites
                (domain, url, document_type, province, city, `year`, status, message, run_id, last_crawled)
             VALUES (?, ?, ?, ?, ?, ?, "discovered", ?, "", NOW())'
        );
        $statement->execute([
            $domain,
            $url,
            self::nullable($meta['document_type'] ?? null),
            self::nullable($meta['province'] ?? null),
            self::nullable($meta['city'] ?? null),
            self::nullable($meta['year'] ?? null),
            mb_substr($message, 0, 250),
        ]);
    }

    /**
     * Simpan LOG penemuan domain (satu baris per pencarian) — "semua log".
     *
     * @param list<array<string, mixed>> $hasil detail situs yang ditemukan
     */
    public function saveDiscoveryLog(string $domain, array $hasil, int $jumlahKandidat): void
    {
        $statement = $this->pdo()->prepare(
            'INSERT INTO discovery_logs (domain, jumlah_ditemukan, jumlah_kandidat, log, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        );

        $log = json_encode(
            ['domain' => $domain, 'situs' => $hasil],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) ?: '[]';

        $statement->execute([$domain, count($hasil), $jumlahKandidat, $log]);
    }

    /**
     * Daftar log penemuan domain (untuk panel dashboard).
     *
     * @return list<array<string, mixed>>
     */
    public function discoveryLogs(int $limit = 30): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT * FROM discovery_logs ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(1000, $limit))
        );
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private static function nullable(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : null;
    }
}
