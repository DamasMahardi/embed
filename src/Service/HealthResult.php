<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Hasil pengecekan GET /health pada service worker-ingest.
 */
final class HealthResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $status,
        public readonly ?string $embedding,
        public readonly ?string $error,
        public readonly ?string $raw,
    ) {
    }

    public function summary(): string
    {
        if (!$this->ok) {
            return 'service TIDAK aktif' . ($this->error !== null ? ' (' . $this->error . ')' : '');
        }

        return 'status=' . ($this->status ?? '?') . ' embedding=' . ($this->embedding ?? '?');
    }
}
