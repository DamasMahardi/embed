<?php

declare(strict_types=1);

namespace App\Qdrant;

use App\Support\Logger;
use App\Support\Text;
use RuntimeException;

/**
 * Mengirim berkas vektor hasil crawler (storage/vectors/<site>/<run-id>.jsonl)
 * ke Qdrant, atau mengubahnya menjadi payload NDJSON untuk diunggah manual.
 *
 * Tiga mode (dipilih lewat opsi CLI):
 *   --dry-run     hanya membaca + memvalidasi berkas, tidak menyentuh Qdrant
 *   --out=FILE    ekspor batch payload NDJSON lalu berhenti (unggah manual)
 *   --export-json ekspor SATU berkas JSON {"collection_name":"documents","wait":true,"points":[{"id":...,"vector":[...],"payload":{...}}]} siap kirim (tanpa Qdrant)
 *   (default)     unggah ke Qdrant: cek/buat collection -> PUT .../points
 *
 * Siklus hidup collection mengikuti .env:
 *   QDRANT_VECTOR_SIZE  dimensi yang diharapkan (dipakai untuk membuat
 *                       collection + memvalidasi berkas vektor)
 *   QDRANT_RECREATE     false = buat bila belum ada, true = hapus lalu buat
 *   QDRANT_AUTO_CREATE  true = boleh membuat collection bila belum ada
 *
 * Semua tahap dicatat ke log txt (QDRANT_BEFORE, QDRANT_COLLECTION,
 * QDRANT_BATCH, QDRANT_EXPORT, QDRANT_AFTER) memakai App\Support\Logger,
 * sehingga perlakuan tahap Qdrant sama dengan tahap FETCH/PARSE/EMBED.
 */
final class QdrantPush
{
    /** @var array<string, mixed> */
    private array $stats = [];

    private string $collection = 'documents';

    private string $distance = 'Cosine';

    private int $batchSize = 64;

    /** Dimensi vektor yang diharapkan (QDRANT_VECTOR_SIZE); 0 = ikut berkas. */
    private int $vectorSize = 0;

    /** true = hapus lalu buat ulang collection (QDRANT_RECREATE / --recreate). */
    private bool $recreate = false;

    private bool $dryRun = false;

    private bool $collectionReady = false;

    /** @var resource|null */
    private $outHandle = null;

    /**
     * @param array<string, mixed> $settings bagian "qdrant" pada config/app.php
     * @param array<string, mixed> $paths    bagian "paths" pada config/app.php
     * @param array<string, mixed> $options  opsi CLI (file, site, run, collection, batch, ...)
     */
    public function __construct(
        private array $settings,
        private array $paths,
        private QdrantClient $client,
        private array $options = [],
        private ?Logger $logger = null,
    ) {
    }

    /**
     * Jalankan proses. RuntimeException dilempar hanya untuk galat yang
     * menghentikan seluruh proses (berkas tidak ada, collection tidak cocok);
     * kegagalan satu batch dicatat lalu proses lanjut ke batch berikutnya.
     *
     * @return array<string, mixed> statistik hasil proses
     */
    public function run(): array
    {
        $started = microtime(true);
        $file = $this->resolveFile();
        $site = basename(dirname($file));

        $this->collection = (string) ($this->options['collection'] ?? $this->settings['collection'] ?? 'documents');
        $this->distance = (string) ($this->options['distance'] ?? $this->settings['distance'] ?? 'Cosine');
        $this->batchSize = max(1, (int) ($this->options['batch'] ?? $this->settings['batch_size'] ?? 64));
        $this->vectorSize = max(0, (int) ($this->options['vector_size'] ?? $this->settings['vector_size'] ?? 0));
        $this->recreate = (bool) ($this->options['recreate'] ?? $this->settings['recreate'] ?? false);
        $this->dryRun = (bool) ($this->options['dry_run'] ?? false);

        $outFile = isset($this->options['out']) ? $this->resolveOutFile((string) $this->options['out']) : null;
        $jsonFile = $this->jsonExportPath($file);

        $this->stats = [
            'berkas' => $this->relative($file),
            'site' => $site,
            'collection' => $this->collection,
            'url_qdrant' => $this->client->baseUrl(),
            'mode' => $outFile !== null ? 'ekspor' : ($jsonFile !== null ? 'json' : ($this->dryRun ? 'uji' : 'unggah')),
            'dimensi' => 0,
            'dimensi_harap' => $this->vectorSize,
            'recreate' => $this->recreate,
            'point_terbaca' => 0,
            'point_terkirim' => 0,
            'point_gagal' => 0,
            'batch' => 0,
            'batch_gagal' => 0,
            'collection_dibuat' => false,
            'collection_point' => -1,
            'out_file' => $outFile === null ? null : $this->relative($outFile),
            'out_json' => $jsonFile === null ? null : $this->relative($jsonFile),
            'point_json' => 0,
            'ukuran_json' => 0,
            'problem' => [],
            'status' => 'SUKSES',
            'durasi' => 0.0,
            'log_run' => $this->logger?->runFile(),
        ];

        if ($jsonFile !== null) {
            return $this->exportJson($file, $site, $jsonFile, $started);
        }

        $reader = new VectorFileReader($file);

        $this->logger?->useSite($site);

        $this->log('QDRANT_BEFORE', Logger::STATUS_PROGRESS, [
            'berkas' => $this->stats['berkas'],
            'qdrant' => $this->client->baseUrl(),
            'collection' => $this->collection,
            'batch' => $this->batchSize,
            'mode' => $this->stats['mode'],
            'dimensi_harap' => $this->vectorSize > 0 ? $this->vectorSize : 'ikut berkas',
            'recreate' => $this->recreate ? 'true' : 'false',
        ], 'Mulai proses vektor ke Qdrant');

        if ($outFile !== null) {
            $this->openOut($outFile);
        }

        $batch = [];
        $index = 0;

        try {
            foreach ($reader->points() as $point) {
                $this->stats['point_terbaca']++;

                if ($this->stats['dimensi'] === 0) {
                    $this->stats['dimensi'] = count($point['vector']);

                    // Dimensi berkas harus sama dengan QDRANT_VECTOR_SIZE:
                    // dicek pada point pertama SEBELUM batch mana pun dikirim.
                    if ($this->vectorSize > 0 && $this->stats['dimensi'] !== $this->vectorSize) {
                        throw new RuntimeException(sprintf(
                            'Dimensi vektor pada %s adalah %d, tidak sama dengan QDRANT_VECTOR_SIZE=%d.'
                            . PHP_EOL . '  Samakan QDRANT_VECTOR_SIZE/EMBED_DIMENSION dengan berkas vektor, '
                            . 'atau pakai collection/berkas lain. Tidak ada point yang dikirim ke Qdrant.',
                            $this->stats['berkas'],
                            $this->stats['dimensi'],
                            $this->vectorSize
                        ));
                    }
                }

                $batch[] = $point;

                if (count($batch) >= $this->batchSize) {
                    $this->flush($batch, ++$index);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $this->flush($batch, ++$index);
            }
        } finally {
            if ($this->outHandle !== null && is_resource($this->outHandle)) {
                fclose($this->outHandle);
                $this->outHandle = null;
            }
        }

        $this->stats['problem'] = $reader->problems();

        if ($this->stats['point_terbaca'] === 0) {
            $this->stats['status'] = 'GAGAL';
        } elseif ($outFile === null && !$this->dryRun && $this->stats['batch_gagal'] > 0) {
            $this->stats['status'] = $this->stats['point_terkirim'] > 0 ? 'SUKSES_SEBAGIAN' : 'GAGAL';
        }

        $this->stats['durasi'] = round(microtime(true) - $started, 2);

        if ($outFile === null && $this->stats['point_terkirim'] > 0) {
            $this->stats['collection_point'] = $this->client->pointsCount($this->collection);
        }

        if ($outFile !== null) {
            $this->log('QDRANT_EXPORT', Logger::STATUS_OK, [
                'berkas' => $this->stats['out_file'],
                'batch' => $this->stats['batch'],
                'vektor' => $this->stats['point_terbaca'],
            ], 'Payload Qdrant diekspor untuk diunggah manual');
        }

        $this->log('QDRANT_AFTER', (string) $this->stats['status'], [
            'berkas' => $this->stats['berkas'],
            'collection' => $this->collection,
            'dimensi' => $this->stats['dimensi'],
            'terkirim' => $this->stats['point_terkirim'],
            'gagal' => $this->stats['point_gagal'],
            'batch' => $this->stats['batch'],
            'durasi' => Text::duration((float) $this->stats['durasi']),
        ], $outFile !== null ? 'Ekspor payload Qdrant selesai' : 'Proses vektor ke Qdrant selesai');

        $this->logger?->summary('QDRANT ' . $this->collection, [
            'Berkas vektor' => $this->stats['berkas'],
            'Mode' => $this->stats['mode'],
            'Qdrant' => $this->client->baseUrl(),
            'Collection' => $this->collection,
            'Recreate' => $this->recreate ? 'ya' : 'tidak',
            'Dimensi harap' => $this->vectorSize > 0 ? $this->vectorSize : '-',
            'Dimensi' => $this->stats['dimensi'],
            'Point terbaca' => $this->stats['point_terbaca'],
            'Point terkirim' => $this->stats['point_terkirim'],
            'Point gagal' => $this->stats['point_gagal'],
            'Batch' => $this->stats['batch'] . ' (gagal ' . $this->stats['batch_gagal'] . ')',
            'Point di collection' => $this->stats['collection_point'] < 0 ? '-' : $this->stats['collection_point'],
            'Baris bermasalah' => count($this->stats['problem']),
            'Status' => $this->stats['status'],
            'Durasi' => Text::duration((float) $this->stats['durasi']),
        ]);

        return $this->stats;
    }

    /**
     * Proses satu batch: tulis ke berkas ekspor, atau kirim ke Qdrant.
     *
     * @param list<array<string, mixed>> $batch
     */
    private function flush(array $batch, int $index): void
    {
        $this->stats['batch'] = max((int) $this->stats['batch'], $index);

        if ($this->outHandle !== null) {
            $encoded = json_encode(['points' => $batch], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($encoded === false || fwrite($this->outHandle, $encoded . PHP_EOL) === false) {
                throw new RuntimeException('Gagal menulis berkas ekspor payload Qdrant');
            }

            return;
        }

        if ($this->dryRun) {
            return;
        }

        $this->ensureCollection();

        $attempts = max(1, (int) ($this->settings['retries'] ?? 3));
        $delayMs = max(0, (int) ($this->settings['retry_delay_ms'] ?? 1000));
        $lastError = '';
        $started = microtime(true);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $this->client->upsert($this->collection, $batch);

                $this->stats['point_terkirim'] += count($batch);

                $this->log('QDRANT_BATCH', Logger::STATUS_OK, [
                    'batch' => $index,
                    'vektor' => count($batch),
                    'percobaan' => $attempt,
                    'durasi' => Text::duration(microtime(true) - $started),
                ], 'Batch vektor tersimpan di Qdrant');

                return;
            } catch (RuntimeException $exception) {
                $lastError = $exception->getMessage();

                if ($attempt < $attempts && $delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }
        }

        $this->stats['point_gagal'] += count($batch);
        $this->stats['batch_gagal']++;

        $this->log('QDRANT_BATCH', Logger::STATUS_FAIL, [
            'batch' => $index,
            'vektor' => count($batch),
            'percobaan' => $attempts,
            'galat' => Text::oneLine($lastError, 200),
        ], 'Batch vektor gagal dikirim ke Qdrant');
    }

    /**
     * Pastikan collection ada dan dimensinya sama dengan dimensi yang
     * diharapkan (QDRANT_VECTOR_SIZE bila diisi, selain itu dimensi berkas).
     *
     *   --recreate / QDRANT_RECREATE=true  -> hapus dulu, lalu buat ulang.
     *   --create   / QDRANT_AUTO_CREATE=true -> boleh dibuat bila belum ada.
     */
    private function ensureCollection(): void
    {
        if ($this->collectionReady) {
            return;
        }

        $recreate = $this->recreate;
        $create = $recreate || (bool) ($this->options['create'] ?? $this->settings['auto_create'] ?? false);
        $expected = $this->vectorSize > 0 ? $this->vectorSize : (int) $this->stats['dimensi'];
        $info = $this->client->collection($this->collection);

        if ($info !== null && $recreate) {
            $this->client->deleteCollection($this->collection);

            $this->log('QDRANT_COLLECTION', Logger::STATUS_SKIP, [
                'collection' => $this->collection,
            ], 'Collection lama dihapus (QDRANT_RECREATE/--recreate)');

            $info = null;
        }

        if ($info === null) {
            if (!$create) {
                throw new RuntimeException(
                    'Collection "' . $this->collection . '" belum ada di ' . $this->client->baseUrl() . PHP_EOL
                    . '  Buat dulu : php bin/crawl.php qdrant --create' . PHP_EOL
                    . '  Atau set  : QDRANT_AUTO_CREATE=true / QDRANT_RECREATE=true pada .env' . PHP_EOL
                    . '  Atau manual: PUT ' . $this->client->collectionUrl($this->collection)
                    . ' {"vectors":{"size":' . $expected . ',"distance":"'
                    . QdrantClient::normalizeDistance($this->distance) . '"}}'
                );
            }

            $this->client->createCollection($this->collection, $expected, $this->distance);

            $this->stats['collection_dibuat'] = true;

            $this->log('QDRANT_COLLECTION', Logger::STATUS_OK, [
                'collection' => $this->collection,
                'dimensi' => $expected,
                'jarak' => QdrantClient::normalizeDistance($this->distance),
                'sumber_dimensi' => $this->vectorSize > 0 ? 'QDRANT_VECTOR_SIZE' : 'berkas vektor',
            ], 'Collection Qdrant dibuat');

            $this->collectionReady = true;

            return;
        }

        $size = QdrantClient::vectorSize($info);

        if ($size === 0) {
            throw new RuntimeException(
                'Collection "' . $this->collection . '" memakai named vector; '
                . 'pipeline ini hanya mendukung satu vektor tanpa nama.'
            );
        }

        if ($size !== $expected) {
            throw new RuntimeException(
                'Dimensi collection "' . $this->collection . '" (' . $size . ') tidak sama dengan dimensi yang '
                . 'diharapkan (' . $expected . ($this->vectorSize > 0 ? ' dari QDRANT_VECTOR_SIZE' : ' dari berkas vektor') . '). '
                . 'Pakai --collection lain, samakan QDRANT_VECTOR_SIZE dengan collection yang ada, '
                . 'atau hapus collection lalu ulangi dengan QDRANT_RECREATE=true.'
            );
        }

        $this->log('QDRANT_COLLECTION', Logger::STATUS_OK, [
            'collection' => $this->collection,
            'dimensi' => $size,
            'point' => (int) ($info['result']['points_count'] ?? 0),
        ], 'Collection Qdrant sudah ada');

        $this->collectionReady = true;
    }

    /**
     * Path berkas JSON untuk mode `--export-json` (null bila opsi tidak dipakai).
     *
     *   --export-json                      -> <run-id>.qdrant.json (di folder .jsonl)
     *   --export-json=storage/hasil.json   -> path yang diberikan
     */
    private function jsonExportPath(string $file): ?string
    {
        if (!array_key_exists('export_json', $this->options)) {
            return null;
        }

        if (isset($this->options['out']) && trim((string) $this->options['out']) !== '') {
            throw new RuntimeException(
                'Pilih salah satu: --out (NDJSON per batch) atau --export-json (satu berkas JSON)'
            );
        }

        $value = trim((string) $this->options['export_json']);

        return $value === '' ? QdrantJsonExporter::defaultPath($file) : $this->absolutePath($value);
    }

    /**
     * Mode `--export-json`: hanya menulis satu berkas JSON siap Qdrant, tanpa
     * menyentuh Qdrant sama sekali. Dipakai untuk membuat/memperbarui
     * `<run-id>.qdrant.json` pada run yang berkas vektornya sudah ada.
     *
     * @return array<string, mixed>
     */
    private function exportJson(string $file, string $site, string $outJson, float $started): array
    {
        $maxPoints = max(0, (int) ($this->options['max_points'] ?? $this->settings['export_max_points'] ?? 0));

        $this->logger?->useSite($site);

        $this->log('QDRANT_BEFORE', Logger::STATUS_PROGRESS, [
            'berkas' => $this->stats['berkas'],
            'mode' => 'json',
            'batas_point' => $maxPoints,
        ], 'Mulai ekspor satu berkas JSON siap Qdrant');

        try {
            $export = (new QdrantJsonExporter($file, $this->collection, true))->export($outJson, $maxPoints);
        } catch (\Throwable $exception) {
            $this->stats['status'] = 'GAGAL';
            $this->stats['problem'][] = $exception->getMessage();
            $this->stats['durasi'] = round(microtime(true) - $started, 2);

            $this->log('QDRANT_EXPORT', Logger::STATUS_FAIL, [
                'berkas' => $this->stats['berkas'],
            ], 'Ekspor JSON gagal: ' . $exception->getMessage());

            $this->log('QDRANT_AFTER', 'GAGAL', [
                'berkas' => $this->stats['berkas'],
                'durasi' => Text::duration((float) $this->stats['durasi']),
            ], 'Ekspor berkas JSON selesai dengan galat');

            $this->logger?->summary('QDRANT JSON', [
                'Berkas vektor' => $this->stats['berkas'],
                'Status' => 'GAGAL',
                'Penyebab' => $exception->getMessage(),
            ]);

            return $this->stats;
        }

        $this->stats['dimensi'] = (int) $export['dimensi'];
        $this->stats['point_terbaca'] = (int) $export['point'];
        $this->stats['point_json'] = (int) $export['point'];
        $this->stats['ukuran_json'] = (int) $export['ukuran'];
        $this->stats['problem'] = $export['problem'];
        $this->stats['durasi'] = round(microtime(true) - $started, 2);

        $this->log('QDRANT_EXPORT', Logger::STATUS_OK, [
            'berkas' => $this->stats['out_json'],
            'point' => $this->stats['point_json'],
            'dimensi' => $this->stats['dimensi'],
            'ukuran' => Text::humanBytes($this->stats['ukuran_json']),
            'baris_bermasalah' => count($this->stats['problem']),
            'durasi' => Text::duration((float) $export['durasi']),
        ], 'Satu berkas JSON siap kirim ke Qdrant selesai dibuat');

        $this->log('QDRANT_AFTER', 'SUKSES', [
            'berkas' => $this->stats['out_json'],
            'point' => $this->stats['point_json'],
            'dimensi' => $this->stats['dimensi'],
            'ukuran' => Text::humanBytes($this->stats['ukuran_json']),
            'durasi' => Text::duration((float) $this->stats['durasi']),
        ], 'Ekspor berkas JSON selesai');

        $this->logger?->summary('QDRANT JSON', [
            'Berkas vektor' => $this->stats['berkas'],
            'Berkas JSON' => $this->stats['out_json'],
            'Point' => $this->stats['point_json'],
            'Dimensi' => $this->stats['dimensi'],
            'Ukuran' => Text::humanBytes($this->stats['ukuran_json']),
            'Baris bermasalah' => count($this->stats['problem']),
            'Status' => 'SUKSES',
            'Durasi' => Text::duration((float) $this->stats['durasi']),
        ]);

        return $this->stats;
    }

    /**
     * Tentukan berkas vektor sumber:
     *   --file=...            path eksplisit (relatif root project boleh)
     *   --site=id --run=RUN   berkas matching, atau
     *   (kosong)              berkas .jsonl terbaru pada storage/vectors
     */
    private function resolveFile(): string
    {
        $file = (string) ($this->options['file'] ?? '');

        if ($file !== '') {
            $absolute = $this->absolutePath($file);

            if (!is_file($absolute)) {
                throw new RuntimeException('Berkas vektor tidak ditemukan: ' . $absolute);
            }

            return $absolute;
        }

        $vectorsDir = rtrim((string) ($this->paths['vectors'] ?? base_path('storage/vectors')), DIRECTORY_SEPARATOR);
        $site = (string) ($this->options['site'] ?? '');
        $run = (string) ($this->options['run'] ?? '');

        $pattern = $vectorsDir . DIRECTORY_SEPARATOR
            . ($site === '' ? '*' : Text::slug($site, 60)) . DIRECTORY_SEPARATOR
            . ($run === '' ? '*.jsonl' : $run . '.jsonl');

        $files = glob($pattern) ?: [];

        if ($files === []) {
            throw new RuntimeException(
                'Tidak ada berkas vektor pada ' . $pattern . PHP_EOL
                . '  Jalankan crawl dahulu, atau arahkan --file=storage/vectors/<site>/<run-id>.jsonl'
            );
        }

        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $files[0];
    }

    private function resolveOutFile(string $out): string
    {
        if (trim($out) === '') {
            throw new RuntimeException('Opsi --out butuh path berkas, mis. --out=storage/vektor-qdrant.ndjson');
        }

        $absolute = $this->absolutePath($out);

        ensure_dir(dirname($absolute));

        return $absolute;
    }

    /**
     * Path absolut: path Windows (C:\...) / Unix (/...) dipakai apa adanya,
     * selain itu dianggap relatif terhadap root project.
     */
    private function absolutePath(string $path): string
    {
        $isAbsolute = preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 || str_starts_with($path, '/');

        return $isAbsolute
            ? str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path)
            : base_path($path);
    }

    private function openOut(string $path): void
    {
        $handle = @fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Gagal membuka berkas ekspor: ' . $path);
        }

        $this->outHandle = $handle;
    }

    /**
     * Path absolut -> path relatif terhadap root project (untuk log & tampilan).
     */
    private function relative(string $path): string
    {
        $base = str_replace('\\', '/', base_path()) . '/';

        return str_replace($base, '', str_replace('\\', '/', $path));
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function log(string $stage, string $status, array $fields, ?string $message = null): void
    {
        $this->logger?->event($stage, $status, $fields, $message);
    }
}
