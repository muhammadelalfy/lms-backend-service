<?php

declare(strict_types=1);

namespace Modules\Ai\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Ai\DTOs\DraftQuestionData;
use Modules\Ai\Enums\QuestionState;
use Modules\Ai\Enums\QuestionType;
use Modules\Ai\Models\DraftExam;
use Modules\Ai\Models\DraftQuestion;
use Modules\Ai\Services\DraftAudit;
use Modules\Ai\Support\DraftGuard;
use Modules\Auth\Models\User;

/** A question the teacher writes by hand, appended to the draft (no AI, no units). */
final class AddDraftQuestion
{
    public function __construct(private readonly DraftAudit $audit) {}

    public function handle(User $actor, DraftExam $draft, DraftQuestionData $data): DraftQuestion
    {
        return DB::transaction(function () use ($actor, $draft, $data) {
            DraftGuard::lockEditable($draft);
            $type = QuestionType::from($data->attributes['type']);
            $question = new DraftQuestion($data->attributes + [
                'draft_id' => $draft->id,
                'ordinal' => (int) DraftQuestion::query()->where('draft_id', $draft->id)->max('ordinal') + 1,
                'state' => QuestionState::Pending->value,
                'origin' => 'manual',
            ]);
            if ($type === QuestionType::TrueFalse && empty($question->options)) {
                $question->options = ['صواب', 'خطأ'];
            }
            if ($type->isWritten()) {
                $question->options = null;
            }
            $question->edited_by_teacher = true;
            $question->save();
            $this->audit->record($draft->id, $actor, 'added', $question->id, ['type' => $type->value]);

            return $question;
        });
    }
}
