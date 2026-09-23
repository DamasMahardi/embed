<?php

declare(strict_types=1);

namespace App\Http;

use App\Support\Text;

/**
 * Hasil satu panggilan HTTP (fetch halaman maupun panggilan service).
 */
final class HttpResponse
{
    /**
     * @param array<string, string> $headers header respons, key lowercase
     * @param bool                  $insecureTls true bila permintaan ini berhasil
     *                                           dengan verifikasi sertifikat TLS
     *                                           dimatikan (server mengirim rantai
     *                                           tidak lengkap). Dipakai log agar
     *                                           tetap transparan.
     * @param string                $remoteIp    alamat IP server yang melayani
     *                                           permintaan ini (kosong bila
     *                                           koneksi gagal). Dipakai log supaya
     *                                           host dengan beberapa alamat A
     *                                           (sebagian mati) bisa ditelusuri.
     * @param bool                  $addressRetry respons ini berasal dari
     *                                           percobaan ULANG ke alamat IP lain
     *                                           setelah alamat pertama tidak
     *                                           menjawab (lihat
     *                                           HttpClient::retryByAddress()).
     */
    public function __construct(
        public readonly string $url,
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly string $effectiveUrl,
        public readonly float $durationMs,
        public readonly ?string $error,
        public readonly int $bytes,
        public readonly ?string $contentType,
        public readonly bool $insecureTls = false,
        public readonly string $remoteIp = '',
        public readonly bool $addressRetry = false,
    ) {
    }

    public function ok(): bool
    {
        return $this->error === null && $this->status >= 200 && $this->status < 300;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function durationSeconds(): float
    {
        return $this->durationMs / 1000;
    }

    /**
     * Cuplikan body satu baris untuk log (mis. pesan error service).
     */
    public function preview(int $maxLength = 300): string
    {
        return Text::oneLine($this->body, $maxLength);
    }

    /**
     * Pesan error ringkas: error curl atau body respons dari service.
     */
    public function errorMessage(int $previewLength = 300): string
    {
        if ($this->error !== null) {
            return $this->error;
        }

        return $this->preview($previewLength);
    }
}
