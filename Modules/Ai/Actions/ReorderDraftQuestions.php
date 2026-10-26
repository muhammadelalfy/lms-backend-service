<?php

declare(strict_types=1);

namespace Modules\Ai\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Ai\Enums\QuestionState;
use Modules\Ai\Exceptions\GenerationRefused;
use Modules\Ai\Models\DraftExam;
use Modules\Ai\Models\DraftQuestion;
use Modules\Ai\Services\DraftAudit;
use Modules\Ai\Support\BulkUpdate;
use Modules\Ai\Support\DraftGuard;
use Modules\Auth\Models\User;

/** Puts the draft's questions in the order given (every live question exactly once). One statement. */
final class ReorderDraftQuestions
{
    public function __construct(private readonly DraftAudit $audit) {}

    /** @param  list<int>  $ids */
    public function handle(User $actor, DraftExam $draft, array $ids): void
    {
        DB::transaction(function () use ($actor, $draft, $ids) {
            DraftGuard::lockEditable($draft);
            $live = DraftQuestion::query()->where('draft_id', $draft->id)->whereIn('state', QuestionState::liveValues())->pluck('id')->map(fn ($id) => (int) $id)->all();
            $ids = array_map('intval', $ids);
            if (count($ids) !== count($live) || array_diff($live, $ids) !== []) {
                throw new GenerationRefused('invalid_order', 'أرسل كل أسئلة المسودة مرة واحدة بالترتيب الجديد.', 422);
            }
            $rows = [];
            foreach ($ids as $i => $id) {
                $rows[$id] = ['ordinal' => $i + 1];
            }
            BulkUpdate::byId('ai_draft_questions', $rows);
            $this->audit->record($draft->id, $actor, 'reordered', null, ['count' => count($ids)]);
        });
    }
}
