<?php

declare(strict_types=1);

namespace Modules\Ai\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Ai\DTOs\DraftQuestionData;
use Modules\Ai\Models\DraftQuestion;
use Modules\Ai\Services\DraftAudit;
use Modules\Ai\Support\DraftGuard;
use Modules\Auth\Models\User;

/** The teacher edits a question; its approval state stays, the edit is audited (field names only). */
final class EditDraftQuestion
{
    public function __construct(private readonly DraftAudit $audit) {}

    public function handle(User $actor, DraftQuestion $question, DraftQuestionData $data): DraftQuestion
    {
        return DB::transaction(function () use ($actor, $question, $data) {
            DraftGuard::lockEditable($question->draft()->firstOrFail());
            $question = DraftQuestion::query()->whereKey($question->id)->lockForUpdate()->firstOrFail();
            DraftGuard::ensureLive($question);

            $question->fill($data->attributes);
            if ($question->type->isWritten()) {
                $question->options = null;
            } else {
                $question->rubric = null;
                $question->model_answer = null;
            }
            $changed = array_keys($question->getDirty());
            if ($changed !== []) {
                $question->edited_by_teacher = true;
                $question->save();
                $this->audit->record($question->draft_id, $actor, 'edited', $question->id, ['fields' => $changed]);
            }

            return $question;
        });
    }
}
