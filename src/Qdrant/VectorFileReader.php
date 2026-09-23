<?php

declare(strict_types=1);

namespace App\Qdrant;

use App\Support\Uuid;
use RuntimeException;

/**
 * Membaca berkas vektor hasil crawler (JSON Lines) menjadi point Qdrant.
 *
 * Satu baris JSONL = satu chunk + `embedding` 1024 float. Pemetaannya:
 *   chunk_id   -> dasar id point (lihat pointId())
 *   embedding  -> vector
 *   sisanya    -> payload    (document_id, chunk_no, checksum, title, url,
 *                             page, heading, section, content, document_type,
 *                             province, city, year, source, sha256, object_key,
 *                             file_url, status, created_at, updated_at)
 *
 * Karena itu hasilnya sama persis dengan isi `<run-id>.qdrant.json` (lihat
 * QdrantJsonExporter): {"id":..., "vector":[...], "payload":{...}}.
 * `content` ikut disimpan supaya bisa dikembalikan saat pencarian / ditampilkan
 * di dashboard.
 *
 * Baris yang rusak (JSON tidak valid / tanpa `embedding`) tidak menghentikan
 * proses: baris tersebut dilewati dan dicatat pada problems().
 */
final class VectorFileReader
{
    /** @var list<string> */
    private array $problems = [];

    public function __construct(private string $path)
    {
        if (!is_file($this->path)) {
            throw new RuntimeException('Berkas vektor tidak ditemukan: ' . $this->path);
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Baris yang dilewati beserta alasannya (bila ada).
     *
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * Iterator point siap kirim ke Qdrant. Field yang dikeluarkan hanya
     * `id`, `vector`, dan `payload` supaya badan permintaan ke Qdrant sesuai
     * skema PointStruct (tanpa field internal tambahan).
     *
     * @return \Generator<int, array{id: string|int, vector: list<float>, payload: array<string, mixed>}>
     */
    public function points(): \Generator
    {
        $handle = @fopen($this->path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Gagal membuka berkas vektor: ' . $this->path);
        }

        $line = 0;

        try {
            while (($raw = fgets($handle)) !== false) {
                $line++;
                $raw = trim($raw);

                if ($raw === '') {
                    continue;
                }

                $record = json_decode($raw, true);

                if (!is_array($record)) {
                    $this->problems[] = 'baris ' . $line . ' dilewati: JSON tidak valid';

                    continue;
                }

                $embedding = $record['embedding'] ?? null;

                if (!is_array($embedding) || $embedding === []) {
                    $this->problems[] = 'baris ' . $line . ' dilewati: field embedding kosong';

                    continue;
                }

                $payload = $record;
                unset($payload['embedding']);

                yield [
                    'id' => self::pointId($record, $line),
                    'vector' => array_values(array_map(static fn (mixed $value): float => (float) $value, $embedding)),
                    'payload' => $payload,
                ];
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * id point: pakai `chunk_id` bila UUID atau bilangan bulat (keduanya sah
     * untuk Qdrant). Selain itu -- payload crawler sekarang memakai chunk_id
     * "<document_id>:<chunk_no>" seperti pipeline dokumen lain -- id dibuat
     * deterministik (UUID v5) dari document_id + chunk_no.
     *
     * checksum sengaja TIDAK ikut di-hash: isi dokumen yang berubah saat
     * re-crawl tetap menimpa point yang sama sehingga point lama tidak
     * tertinggal (orphan) di collection.
     *
     * @param array<string, mixed> $record
     */
    private static function pointId(array $record, int $line): string|int
    {
        $chunkId = $record['chunk_id'] ?? null;

        if (is_int($chunkId)) {
            return $chunkId;
        }

        if (is_string($chunkId)) {
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $chunkId) === 1) {
                return strtolower($chunkId);
            }

            if (ctype_digit($chunkId) && $chunkId !== '') {
                return (int) $chunkId;
            }
        }

        return Uuid::v5(
            'chunk:' . (string) ($record['document_id'] ?? '-')
            . ':' . (string) ($record['chunk_no'] ?? $line)
        );
    }
}
