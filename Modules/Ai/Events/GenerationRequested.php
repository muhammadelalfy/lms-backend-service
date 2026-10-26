<?php

declare(strict_types=1);

namespace Modules\Ai\Events;

/** A teacher started a generation (or regeneration) job; dispatched after the job row is committed. */
final class GenerationRequested
{
    public function __construct(public readonly int $jobId, public readonly int $draftId, public readonly int $requestedBy, public readonly string $kind) {}
}
