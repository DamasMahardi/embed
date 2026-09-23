<?php

declare(strict_types=1);

namespace App\Crawl;

/**
 * Hasil unduhan satu halaman HTML.
 */
final class FetchResult
{
    /**
     * @param string $remoteIp     alamat IP server yang melayani unduhan
     *                             (kosong bila koneksi gagal)
     * @param bool   $addressRetry unduhan berhasil setelah dicoba ULANG ke alamat
     *                             IP lain karena alamat pertama tidak menjawab
     *                             (lihat HttpClient::retryByAddress())
     */
    public function __construct(
        public readonly string $url,
        public readonly string $effectiveUrl,
        public readonly int $httpStatus,
        public readonly bool $ok,
        public readonly bool $isHtml,
        public readonly ?string $contentType,
        public readonly int $bytes,
        public readonly float $durationSeconds,
        public readonly ?string $path,
        public readonly ?string $charset,
        public readonly ?string $error,
        public readonly bool $insecureTls = false,
        public readonly string $remoteIp = '',
        public readonly bool $addressRetry = false,
    ) {
    }

    public static function failure(
        string $url,
        int $httpStatus,
        string $error,
        float $durationSeconds = 0.0,
        string $remoteIp = '',
        bool $addressRetry = false,
    ): self {
        return new self(
            $url,
            $url,
            $httpStatus,
            false,
            false,
            null,
            0,
            $durationSeconds,
            null,
            null,
            $error,
            remoteIp: $remoteIp,
            addressRetry: $addressRetry,
        );
    }

    public function extension(): string
    {
        $path = parse_url($this->effectiveUrl, PHP_URL_PATH);
        $extension = is_string($path) ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';

        return $extension !== '' && strlen($extension) <= 5 ? $extension : 'html';
    }

    /**
     * Salinan hasil unduhan dengan lokasi berkas berbeda.
     *
     * Dipakai bila berkas hasil unduhan dipindahkan/dinamai ulang setelah
     * Content-Type diketahui (mis. URL tanpa ekstensi ternyata mengirim PDF:
     * berkas dipindahkan ke storage/documents dengan ekstensi .pdf).
     */
    public function withPath(string $path): self
    {
        return new self(
            url: $this->url,
            effectiveUrl: $this->effectiveUrl,
            httpStatus: $this->httpStatus,
            ok: $this->ok,
            isHtml: $this->isHtml,
            contentType: $this->contentType,
            bytes: $this->bytes,
            durationSeconds: $this->durationSeconds,
            path: $path,
            charset: $this->charset,
            error: $this->error,
            insecureTls: $this->insecureTls,
            remoteIp: $this->remoteIp,
            addressRetry: $this->addressRetry,
        );
    }
}
