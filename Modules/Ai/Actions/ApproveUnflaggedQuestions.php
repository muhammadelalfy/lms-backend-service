<?php

declare(strict_types=1);

namespace Modules\Ai\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Ai\Enums\QuestionState;
use Modules\Ai\Models\DraftExam;
use Modules\Ai\Models\DraftQuestion;
use Modules\Ai\Services\DraftAudit;
use Modules\Ai\Support\DraftGuard;
use Modules\Auth\Models\User;

/**
 * "Approve all unflagged": every pending question that is not flagged and was verified as supported
 * (or that the teacher wrote by hand). Flagged and unsupported questions always need a look. One statement.
 */
final class ApproveUnflaggedQuestions
{
    public function __construct(private readonly DraftAudit $audit) {}

    /** @return int how many questions were approved */
    public function handle(User $actor, DraftExam $draft): int
    {
        return DB::transaction(function () use ($actor, $draft) {
            DraftGuard::lockEditable($draft);
            $count = DraftQuestion::query()->where('draft_id', $draft->id)
                ->where('state', QuestionState::Pending->value)->where('flagged', false)
                ->where(fn ($q) => $q->where('verify_verdict', 'supported')
                    ->orWhere(fn ($m) => $m->whereNull('verify_verdict')->where('origin', 'manual')))
                ->update(['state' => QuestionState::Approved->value, 'updated_at' => now()]);
            if ($count > 0) {
                $this->audit->record($draft->id, $actor, 'approved_all_unflagged', null, ['count' => $count]);
            }

            return $count;
        });
    }
}
