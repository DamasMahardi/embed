<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Hasil panggilan POST /embed.
 */
final class EmbedResult
{
    /**
     * @param list<list<float>> $embeddings
     */
    public function __construct(
        public readonly bool $ok,
        public readonly int $httpStatus,
        public readonly array $embeddings,
        public readonly int $dimension,
        public readonly ?string $error,
        public readonly float $durationSeconds,
        public readonly int $attempts = 1,
    ) {
    }

    public function count(): int
    {
        return count($this->embeddings);
    }
}
