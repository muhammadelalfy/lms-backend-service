<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Modules\Ai\Models\DraftEvent;
use Modules\Auth\Models\User;

/** Records the decisions on a draft. Details hold ids, field names and counts, never question text. */
final class DraftAudit
{
    /** @param  array<string, mixed>  $details */
    public function record(int $draftId, ?User $actor, string $action, ?int $questionId = null, array $details = []): void
    {
        DraftEvent::create([
            'draft_id' => $draftId,
            'question_id' => $questionId,
            'actor_id' => $actor?->id,
            'action' => $action,
            'details' => $details ?: null,
            'created_at' => now(),
        ]);
    }
}
