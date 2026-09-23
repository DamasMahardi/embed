<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Hasil panggilan POST /parse.
 */
final class ParseResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly int $httpStatus,
        public readonly string $markdown,
        public readonly string $title,
        public readonly int $pageCount,
        public readonly int $processedMs,
        public readonly ?string $error,
        public readonly float $durationSeconds,
        public readonly bool $webIngestDisabled,
        public readonly int $attempts = 1,
    ) {
    }

    public function markdownLength(): int
    {
        return mb_strlen($this->markdown);
    }
}
