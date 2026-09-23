<?php

declare(strict_types=1);

namespace App\Qdrant;

use App\Http\HttpClient;
use App\Http\HttpResponse;
use App\Support\Text;
use RuntimeException;

/**
 * Klien tipis REST Qdrant (tanpa library eksternal, cukup ekstensi curl PHP).
 *
 * Endpoint yang dipakai:
 *   GET    /collections/{nama}                    info collection + jumlah point
 *   PUT    /collections/{nama}                    buat collection (size + distance)
 *   DELETE /collections/{nama}                    hapus collection (opsi --recreate)
 *   PUT    /collections/{nama}/points?wait=true   unggah point per batch
 *
 * Semua method melempar RuntimeException berisi pesan Qdrant apa adanya supaya
 * mudah dicatat pada log txt. Khusus collection(), hasil null berarti HTTP 404
 * alias collection belum ada (bukan galat).
 */
final class QdrantClient
{
    /** Metrik jarak yang dikenal Qdrant (ejaan sesuai dokumentasi REST). */
    public const DISTANCES = ['Cosine', 'Euclid', 'Dot', 'Manhattan'];

    public function __construct(
        private HttpClient $http,
        private string $baseUrl,
        private string $apiKey = '',
        private int $timeout = 120,
    ) {
        $this->baseUrl = rtrim($this->baseUrl, '/');
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function collectionUrl(string $collection): string
    {
        return $this->baseUrl . '/collections/' . rawurlencode($collection);
    }

    /**
     * Info collection; null bila collection belum ada.
     *
     * @return array<string, mixed>|null
     */
    public function collection(string $collection): ?array
    {
        $response = $this->send('GET', $this->collectionUrl($collection));

        if ($response->status === 404) {
            return null;
        }

        return $this->decode($response, 'baca collection "' . $collection . '"');
    }

    /**
     * Dimensi (size) vektor yang dipakai collection; 0 bila belum ada atau
     * collection memakai named vector (belum didukung pipeline ini).
     *
     * @param array<string, mixed> $info hasil collection()
     */
    public static function vectorSize(array $info): int
    {
        $vectors = $info['result']['config']['params']['vectors'] ?? null;

        if (!is_array($vectors) || !isset($vectors['size'])) {
            return 0;
        }

        return (int) $vectors['size'];
    }

    /**
     * Jumlah point pada collection; -1 bila tidak bisa dibaca.
     */
    public function pointsCount(string $collection): int
    {
        try {
            $info = $this->collection($collection);
        } catch (RuntimeException) {
            return -1;
        }

        return $info === null ? -1 : (int) ($info['result']['points_count'] ?? -1);
    }

    /**
     * Buat collection baru dengan satu vektor tak bernama (unnamed vector).
     */
    public function createCollection(string $collection, int $dimension, string $distance): void
    {
        $payload = [
            'vectors' => [
                'size' => $dimension,
                'distance' => self::normalizeDistance($distance),
            ],
        ];

        $response = $this->send('PUT', $this->collectionUrl($collection), $payload);

        $this->decode($response, 'buat collection "' . $collection . '"');
    }

    /**
     * Hapus collection; 404 dianggap sukses (memang belum ada).
     */
    public function deleteCollection(string $collection): void
    {
        $response = $this->send('DELETE', $this->collectionUrl($collection));

        if ($response->status === 404) {
            return;
        }

        $this->decode($response, 'hapus collection "' . $collection . '"');
    }

    /**
     * Unggah satu batch point (id + vector + payload).
     *
     * @param list<array<string, mixed>> $points
     *
     * @return array<string, mixed> respons Qdrant
     */
    public function upsert(string $collection, array $points): array
    {
        $url = $this->collectionUrl($collection) . '/points?wait=true';

        // Kirim hanya field yang dikenal Qdrant (id, vector, payload) supaya
        // tidak ada kunci internal pipeline yang ikut terkirim.
        $body = [];
        foreach ($points as $point) {
            $body[] = [
                'id' => $point['id'],
                'vector' => $point['vector'],
                'payload' => $point['payload'] ?? [],
            ];
        }

        $response = $this->send('PUT', $url, ['points' => $body]);

        return $this->decode($response, 'upsert ' . count($points) . ' point');
    }

    /**
     * Ejaan metrik jarak yang benar (cosine -> Cosine); menolak nilai asing.
     */
    public static function normalizeDistance(string $distance): string
    {
        foreach (self::DISTANCES as $known) {
            if (strcasecmp($known, trim($distance)) === 0) {
                return $known;
            }
        }

        throw new RuntimeException(
            'Metrik jarak tidak dikenal: ' . $distance . '. Pilihan: ' . implode(', ', self::DISTANCES)
        );
    }

    /**
     * Satuan timeout yang diterima: DETIK bila nilainya < 1000 (mis. 120 =
     * 120 detik) dan MILIDETIK bila >= 1000 (mis. QDRANT_TIMEOUT=10000 = 10
     * detik). QdrantClient/curl memakai detik, jadi nilai milidetik diubah di
     * sini supaya .env gaya Node/JS (ms) tetap bisa dipakai apa adanya.
     *
     * @param int|string|mixed $value nilai mentah dari .env/opsi CLI
     */
    public static function timeoutSeconds(mixed $value, int $default = 120): int
    {
        if (!is_numeric($value)) {
            return $default;
        }

        $number = (int) $value;

        if ($number <= 0) {
            return $default;
        }

        return $number >= 1000 ? max(1, (int) round($number / 1000)) : $number;
    }

    /**
     * @param array<string, mixed>|null $payload null = tanpa body (mis. DELETE collection)
     */
    private function send(string $method, string $url, ?array $payload = null): HttpResponse
    {
        $options = [
            'timeout' => $this->timeout,
            'connect_timeout' => min(10, $this->timeout),
            'headers' => ['Accept' => 'application/json'],
        ];

        if ($this->apiKey !== '') {
            $options['headers']['api-key'] = $this->apiKey;
        }

        if ($method === 'GET') {
            return $this->http->get($url, $options);
        }

        return $method === 'DELETE'
            ? $this->http->deleteJson($url, $payload ?? [], $options)
            : $this->http->putJson($url, $payload ?? [], $options);
    }

    /**
     * Pastikan respons HTTP + status JSON Qdrant sehat, lalu kembalikan isinya.
     *
     * @return array<string, mixed>
     */
    private function decode(HttpResponse $response, string $context): array
    {
        if ($response->error !== null) {
            throw new RuntimeException($context . ': ' . Text::oneLine($response->error, 240));
        }

        if (!$response->ok()) {
            throw new RuntimeException(
                $context . ': HTTP ' . $response->status . ' ' . Text::oneLine(trim($response->body), 240)
            );
        }

        $decoded = json_decode(trim($response->body), true);

        if (!is_array($decoded)) {
            throw new RuntimeException(
                $context . ': respons Qdrant bukan JSON (' . Text::oneLine($response->body, 160) . ')'
            );
        }

        if (($decoded['status'] ?? '') !== 'ok') {
            throw new RuntimeException($context . ': ' . Text::oneLine(self::errorMessage($decoded), 300));
        }

        return $decoded;
    }

    /**
     * Ambil pesan galat dari badan respons Qdrant
     * ({"status":{"error":"..."}} atau {"status":"..."}).
     *
     * @param array<string, mixed> $decoded
     */
    private static function errorMessage(array $decoded): string
    {
        $status = $decoded['status'] ?? 'status tidak ok';

        if (is_array($status)) {
            $encoded = json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return (string) ($status['error'] ?? ($encoded === false ? 'status tidak ok' : $encoded));
        }

        return (string) $status;
    }
}
