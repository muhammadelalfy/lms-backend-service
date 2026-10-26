<?php

declare(strict_types=1);

namespace Modules\Ai\Events;

/** A generation job reached a final state (ready, needs_attention or failed). Dispatched after its row is saved. */
final class GenerationCompleted
{
    public function __construct(
        public readonly int $jobId,
        public readonly int $draftId,
        public readonly int $requestedBy,
        public readonly string $status,
    ) {}
}
