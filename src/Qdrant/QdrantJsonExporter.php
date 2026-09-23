<?php

declare(strict_types=1);

namespace App\Qdrant;

use RuntimeException;

/**
 * Mengubah berkas vektor hasil crawl (`storage/vectors/<site>/<run-id>.jsonl`)
 * menjadi SATU berkas JSON siap kirim ke Qdrant:
 *
 *   storage/vectors/<site>/<run-id>.qdrant.json
 *
 *   {
 *     "collection_name": "documents",
 *     "wait": true,
 *     "points": [
 *       {
 *         "id": "<uuid v5 dari document_id + chunk_no>",
 *         "vector": [0.0123, -0.0456, 0.0789, "..."],
 *         "payload": {
 *           "document_id": "<uuid-v5 dari url>",
 *           "chunk_id": "<document_id>:<chunk_no> (mis. 2ae2f337-...:0)",
 *           "chunk_no": 0,
 *           "checksum": "abc123... (sha256 hex isi chunk)",
 *           "title": "Judul dokumen",
 *           "url": "https://example.com/dokumen",
 *           "page": null,
 *           "heading": "Pendapatan Daerah",
 *           "section": "APBD 2024",
 *           "content": "Isi dokumen dalam Markdown...",
 *           "document_type": "APBD",
 *           "province": "Banten",
 *           "city": "Kabupaten Serang",
 *           "year": 2024,
 *           "source": "example.com",
 *           "sha256": "abc123...",
 *           "object_key": "storage/documents/...",
 *           "file_url": null,
 *           "status": "READY",
 *           "created_at": "2026-09-16T10:00:00.000Z",
 *           "updated_at": "2026-09-16T10:00:00.000Z"
 *         }
 *       }
 *     ]
 *   }
 *
 * Isi berkasnya persis badan permintaan `PUT /collections/<collection>/points`,
 * jadi bisa dikirim apa adanya tanpa perlakuan tambahan:
 *
 *   Invoke-RestMethod -Method Put -ContentType 'application/json' `
 *     -Uri 'http://localhost:6333/collections/documents/points?wait=true' `
 *     -InFile storage\vectors\dindikbud-serangkab-go-id\RUN-20260916-031217.qdrant.json
 *
 * Catatan format:
 *   - Hanya kunci `id`, `vector`, dan `payload` yang ditulis pada tiap point
 *     (sesuai skema PointStruct Qdrant): `embedding` pada .jsonl dipindahkan
 *     menjadi `vector`, `chunk_id` dipakai sebagai dasar `id`, dan sisanya
 *     menjadi `payload`.
 *   - Satu point ditulis per baris (baris baru antar token tetap JSON sah),
 *     supaya berkas tetap bisa diperiksa manusia walau berisi ribuan float.
 *   - Penulisan memakai berkas sementara lalu rename, sehingga berkas setengah
 *     jadi tidak pernah bisa terbaca/diunggah.
 *   - Semua vektor diverifikasi berdimensi sama; ketidakcocokan menghentikan
 *     ekspor (Qdrant akan menolaknya juga, tetapi lebih baik gagal di sini).
 */
final class QdrantJsonExporter
{
    /** @var list<string> */
    private array $problems = [];

    private int $points = 0;

    private int $dimension = 0;

    public function __construct(
        private string $jsonl,
        private string $collectionName = 'documents',
        private bool $wait = true,
    )
    {
        if (!is_file($this->jsonl)) {
            throw new RuntimeException('Berkas vektor tidak ditemukan: ' . $this->jsonl);
        }
    }

    /**
     * Nama berkas JSON bawaan untuk sebuah .jsonl:
     * `RUN-20260916-031217.jsonl` -> `RUN-20260916-031217.qdrant.json`.
     */
    public static function defaultPath(string $jsonl): string
    {
        return preg_replace('/\.jsonl$/i', '', $jsonl) . '.qdrant.json';
    }

    /**
     * Tulis berkas JSON hasil pemetaan vektor.
     *
     * @param int $maxPoints 0 = tanpa batas. Bila jumlah point melebihi batas,
     *                       ekspor dibatalkan (tanpa sisa berkas) dan pemanggil
     *                       diarahkan memakai unggahan bertahap (--out NDJSON).
     *
     * @return array{berkas: string, out: string, point: int, dimensi: int, ukuran: int, durasi: float, problem: list<string>}
     */
    public function export(string $outPath, int $maxPoints = 0): array
    {
        $started = microtime(true);

        ensure_dir(dirname($outPath));

        $temporary = $outPath . '.tmp';
        $handle = @fopen($temporary, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Gagal membuka berkas ekspor: ' . $temporary);
        }

        $reader = new VectorFileReader($this->jsonl);

        try {
            $header = json_encode([
                'collection_name' => $this->collectionName,
                'wait' => $this->wait,
                'points' => [],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($header === false) {
                throw new RuntimeException('Gagal membuat header ekspor Qdrant');
            }

            // Buang penutup "[]}" dari header lalu buka array points:
            // {"collection_name":...,"wait":true,"points":[]} -> ..."points":[
            fwrite($handle, substr($header, 0, -3) . '[');

            foreach ($reader->points() as $point) {
                $size = count($point['vector']);

                if ($this->dimension === 0) {
                    $this->dimension = $size;
                } elseif ($size !== $this->dimension) {
                    throw new RuntimeException(sprintf(
                        'Dimensi vektor tidak konsisten pada point ke-%d (%d, seharusnya %d) di %s',
                        $this->points + 1,
                        $size,
                        $this->dimension,
                        $this->jsonl
                    ));
                }

                if ($maxPoints > 0 && $this->points >= $maxPoints) {
                    throw new RuntimeException(sprintf(
                        'Lebih dari %d point pada %s sehingga tidak diekspor ke satu berkas JSON '
                        . '(batas QDRANT_EXPORT_MAX_POINTS). Untuk run sebesar ini pakai unggahan '
                        . 'bertahap: php bin/crawl.php qdrant --file=... --batch=64 '
                        . '(atau --out=storage/vektor-qdrant.ndjson)',
                        $maxPoints,
                        $this->jsonl
                    ));
                }

                $encoded = json_encode($point, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                if ($encoded === false) {
                    $this->problems[] = 'point ke-' . ($this->points + 1) . ' dilewati: gagal di-encode JSON';

                    continue;
                }

                fwrite($handle, ($this->points === 0 ? PHP_EOL : ',' . PHP_EOL) . $encoded);
                $this->points++;
            }

            fwrite($handle, PHP_EOL . ']}' . PHP_EOL);
        } catch (\Throwable $exception) {
            fclose($handle);
            @unlink($temporary);

            throw $exception;
        }

        fclose($handle);

        if ($this->points === 0) {
            @unlink($temporary);

            throw new RuntimeException(
                'Tidak ada point bervektor pada ' . $this->jsonl . ' (berkas kosong atau tanpa field embedding)'
            );
        }

        $this->commit($temporary, $outPath);

        $this->problems = array_merge($reader->problems(), $this->problems);

        return [
            'berkas' => $this->jsonl,
            'out' => $outPath,
            'point' => $this->points,
            'dimensi' => $this->dimension,
            'ukuran' => (int) filesize($outPath),
            'durasi' => round(microtime(true) - $started, 2),
            'problem' => $this->problems,
        ];
    }

    /**
     * Pindahkan berkas sementara ke path tujuan (menimpa berkas lama).
     */
    private function commit(string $temporary, string $outPath): void
    {
        if (@rename($temporary, $outPath)) {
            return;
        }

        @unlink($outPath);

        if (!@rename($temporary, $outPath)) {
            @unlink($temporary);

            throw new RuntimeException('Gagal memindahkan berkas ekspor ke ' . $outPath);
        }
    }
}
