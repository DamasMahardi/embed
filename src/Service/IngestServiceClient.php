<?php

declare(strict_types=1);

namespace App\Service;

use App\Http\HttpClient;
use App\Http\HttpResponse;

/**
 * Client service worker-ingest (FastAPI).
 *
 *  GET  /health  -> {status, embedding}
 *  POST /parse   -> multipart file -> {title, page_count, pages, markdown, processed_ms}
 *  POST /embed   -> {texts:[...]}   -> {embeddings:[[float,...]], dimension}
 *
 * Catatan penting: service ini TIDAK menerima URL. Crawler harus mengunduh
 * halaman lebih dulu, lalu mengirim file HTML ke /parse.
 */
final class IngestServiceClient
{
    private string $baseUrl;

    /**
     * @param array<string, mixed> $config bagian config app.php "service"
     */
    public function __construct(
        private HttpClient $http,
        private array $config,
    ) {
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? 'http://localhost:8001'), '/');
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function parseUrl(): string
    {
        return $this->baseUrl . (string) ($this->config['parse_path'] ?? '/parse');
    }

    public function embedUrl(): string
    {
        return $this->baseUrl . (string) ($this->config['embed_path'] ?? '/embed');
    }

    public function healthUrl(): string
    {
        return $this->baseUrl . (string) ($this->config['health_path'] ?? '/health');
    }

    public function health(): HealthResult
    {
        $response = $this->http->get($this->healthUrl(), [
            'timeout' => 15,
            'headers' => $this->authHeaders(),
        ]);

        if (!$response->ok()) {
            return new HealthResult(
                ok: false,
                status: null,
                embedding: null,
                error: $response->errorMessage(200),
                raw: $response->body !== '' ? $response->body : null,
            );
        }

        $json = $response->json() ?? [];

        return new HealthResult(
            ok: true,
            status: isset($json['status']) ? (string) $json['status'] : null,
            embedding: isset($json['embedding']) ? (string) $json['embedding'] : null,
            error: null,
            raw: $response->body,
        );
    }

    /**
     * Kirim satu berkas (HTML atau dokumen pdf/docx/xlsx/...) ke /parse dan
     * ambil markdown hasil parsing.
     *
     * Nama file dipertahankan apa adanya karena parse_pdf.py memilih parser
     * berdasarkan EKSTENSI file (.html/.htm -> parser web, .pdf -> PDF, dst.),
     * sehingga Content-Type dikirim sesuai ekstensi tersebut.
     */
    public function parse(string $filePath, ?int $timeout = null, ?int $maxPages = null, ?string $mimeType = null): ParseResult
    {
        $timeout ??= (int) ($this->config['parse_timeout'] ?? 600);
        $maxPages ??= (int) ($this->config['parse_max_pages'] ?? 500);
        $attempts = 0;
        $startedAt = microtime(true);

        $parts = [
            'file' => new \CURLFile(
                $filePath,
                $mimeType ?? self::mimeTypeFor($filePath),
                basename($filePath)
            ),
            'timeout' => (string) $timeout,
            'max_pages' => (string) $maxPages,
        ];

        $response = $this->withRetry(function () use ($parts, $timeout, &$attempts): HttpResponse {
            $attempts++;

            return $this->http->postMultipart($this->parseUrl(), $parts, [
                'timeout' => $timeout + 60,
                'headers' => $this->authHeaders(),
                'max_bytes' => 128 * 1024 * 1024,
            ]);
        }, $attempts);

        $duration = microtime(true) - $startedAt;

        if ($response === null) {
            return new ParseResult(false, 0, '', '', 0, 0, 'tidak ada respons dari /parse', $duration, false, $attempts);
        }

        $json = $response->json();

        if (!$response->ok() || $json === null) {
            $disabled = $response->status === 422 && stripos($response->body, 'web ingestion disabled') !== false;

            return new ParseResult(
                ok: false,
                httpStatus: $response->status,
                markdown: '',
                title: '',
                pageCount: 0,
                processedMs: 0,
                error: $response->errorMessage(400),
                durationSeconds: $duration,
                webIngestDisabled: $disabled,
                attempts: $attempts,
            );
        }

        $markdown = isset($json['markdown']) && is_string($json['markdown']) ? $json['markdown'] : '';

        return new ParseResult(
            ok: true,
            httpStatus: $response->status,
            markdown: $markdown,
            title: isset($json['title']) ? (string) $json['title'] : '',
            pageCount: isset($json['page_count']) ? (int) $json['page_count'] : 0,
            processedMs: isset($json['processed_ms']) ? (int) $json['processed_ms'] : 0,
            error: null,
            durationSeconds: $duration,
            webIngestDisabled: false,
            attempts: $attempts,
        );
    }

    /**
     * Kirim satu batch teks ke /embed.
     *
     * @param list<string> $texts
     */
    public function embed(array $texts, ?int $timeout = null): EmbedResult
    {
        $timeout ??= (int) ($this->config['embed_timeout'] ?? 300);
        $attempts = 0;
        $startedAt = microtime(true);

        $response = $this->withRetry(function () use ($texts, $timeout, &$attempts): HttpResponse {
            $attempts++;

            return $this->http->postJson($this->embedUrl(), ['texts' => array_values($texts)], [
                'timeout' => $timeout,
                'headers' => $this->authHeaders(),
                'max_bytes' => 512 * 1024 * 1024,
            ]);
        }, $attempts);

        $duration = microtime(true) - $startedAt;

        if ($response === null) {
            return new EmbedResult(false, 0, [], 0, 'tidak ada respons dari /embed', $duration, $attempts);
        }

        $json = $response->json();

        if (!$response->ok() || $json === null || !isset($json['embeddings']) || !is_array($json['embeddings'])) {
            return new EmbedResult(
                ok: false,
                httpStatus: $response->status,
                embeddings: [],
                dimension: 0,
                error: $response->errorMessage(400),
                durationSeconds: $duration,
                attempts: $attempts,
            );
        }

        /** @var list<list<float>> $embeddings */
        $embeddings = [];
        foreach ($json['embeddings'] as $vector) {
            if (!is_array($vector)) {
                continue;
            }

            $embeddings[] = array_map(static fn ($value): float => (float) $value, $vector);
        }

        $dimension = isset($json['dimension'])
            ? (int) $json['dimension']
            : (isset($embeddings[0]) ? count($embeddings[0]) : 0);

        return new EmbedResult(
            ok: true,
            httpStatus: $response->status,
            embeddings: $embeddings,
            dimension: $dimension,
            error: null,
            durationSeconds: $duration,
            attempts: $attempts,
        );
    }

    /**
     * Content-Type berkas yang diunggah ke /parse, ditentukan dari ekstensinya
     * (.html/.htm dan berkas tanpa ekstensi tetap text/html seperti sebelumnya).
     */
    public static function mimeTypeFor(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'odt' => 'application/vnd.oasis.opendocument.text',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
            'rtf' => 'application/rtf',
            'csv' => 'text/csv',
            'txt' => 'text/plain',
            default => 'text/html',
        };
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        $token = (string) ($this->config['token'] ?? '');

        return $token === '' ? [] : ['Authorization' => 'Bearer ' . $token];
    }

    /**
     * Retry dengan exponential backoff untuk kegagalan sementara (error
     * jaringan, 429, 5xx). Error permanen (400/401/403/404/422) dikembalikan
     * langsung supaya log tidak menunjukkan percobaan yang sia-sia.
     *
     * @param callable(): HttpResponse $callback
     */
    private function withRetry(callable $callback, int &$attempts): ?HttpResponse
    {
        $maxAttempts = max(1, (int) ($this->config['retries'] ?? 3) + 1);
        $delayMs = max(0, (int) ($this->config['retry_delay_ms'] ?? 1000));
        $response = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = $callback();

            if ($this->isTransient($response) && $attempt < $maxAttempts) {
                usleep($delayMs * (2 ** ($attempt - 1)) * 1000);
                continue;
            }

            break;
        }

        return $response;
    }

    private function isTransient(HttpResponse $response): bool
    {
        if ($response->error !== null && $response->status === 0) {
            return true;
        }

        return in_array($response->status, [429, 500, 502, 503, 504], true);
    }
}
