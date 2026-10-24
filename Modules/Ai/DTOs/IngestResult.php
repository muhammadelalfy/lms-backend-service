<?php

declare(strict_types=1);

namespace Modules\Ai\DTOs;

/** The chunks the AI service made of a material (POST /v1/materials/{id}/ingest). */
final readonly class IngestResult
{
    /** @param list<array{section_id: int, chunk_ref: string, ordinal: int}> $chunkRefs */
    public function __construct(public int $version, public int $chunks, public array $chunkRefs) {}
}
