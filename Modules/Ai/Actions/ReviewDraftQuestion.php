<?php

declare(strict_types=1);

namespace Modules\Ai\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Ai\Enums\QuestionState;
use Modules\Ai\Models\DraftQuestion;
use Modules\Ai\Services\DraftAudit;
use Modules\Ai\Support\DraftGuard;
use Modules\Auth\Models\User;

/**
 * The teacher's decision on one question: approve, take the approval back, or remove it from the
 * exam (kept as history). Each decision is audited.
 */
final class ReviewDraftQuestion
{
    public function __construct(private readonly DraftAudit $audit) {}

    public function approve(User $actor, DraftQuestion $question): void
    {
        $this->move($actor, $question, QuestionState::Approved, 'approved');
    }

    public function unapprove(User $actor, DraftQuestion $question): void
    {
        $this->move($actor, $question, QuestionState::Pending, 'unapproved');
    }

    public function remove(User $actor, DraftQuestion $question): void
    {
        $this->move($actor, $question, QuestionState::Removed, 'removed');
    }

    private function move(User $actor, DraftQuestion $question, QuestionState $target, string $action): void
    {
        DB::transaction(function () use ($actor, $question, $target, $action) {
            DraftGuard::lockEditable($question->draft()->firstOrFail());
            $locked = DraftQuestion::query()->whereKey($question->id)->lockForUpdate()->firstOrFail();
            DraftGuard::ensureLive($locked);
            if ($locked->state === $target) {
                return; // the same decision twice changes nothing
            }
            $locked->state = $target;
            $locked->save();
            $this->audit->record($locked->draft_id, $actor, $action, $locked->id);
        });
    }
}
