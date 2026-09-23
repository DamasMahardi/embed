<?php

declare(strict_types=1);

namespace App\Pipeline;

/**
 * Satu potongan (chunk) dokumen beserta payload vektor untuk tahap Qdrant.
 *
 * Struktur payload sengaja dibuat sama dengan crawl-web (src/chunker.ts) agar
 * vektor dari crawler PHP bisa ditelan pipeline yang sudah ada tanpa
 * perubahan skema.
 */
final class Chunk
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $documentId,
        public readonly string $chunkId,
        public readonly int $chunkNo,
        public readonly string $checksum,
        public readonly string $content,
        public readonly array $payload,
    ) {
    }

    public function charCount(): int
    {
        return mb_strlen($this->content);
    }

    public function wordCount(): int
    {
        return str_word_count($this->content, 0, 'àáâãäåçèéêëìíîïñòóôõöùúûüýÿÀÁÂÃÄÅÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜÝ');
    }

    /**
     * Payload ditambah vektor hasil /embed (dipakai sebelum menulis file JSONL).
     *
     * @param list<float> $vector
     *
     * @return array<string, mixed>
     */
    public function withVector(array $vector): array
    {
        return $this->payload + ['embedding' => $vector];
    }
}
