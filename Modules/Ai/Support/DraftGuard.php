<?php

declare(strict_types=1);

namespace Modules\Ai\Support;

use Modules\Ai\Exceptions\GenerationRefused;
use Modules\Ai\Models\DraftExam;
use Modules\Ai\Models\DraftQuestion;

/** The states in which a draft and its questions can still be reviewed. */
final class DraftGuard
{
    /** Locks the draft row for the rest of the transaction and refuses a published (frozen) draft. */
    public static function lockEditable(DraftExam $draft): DraftExam
    {
        $locked = DraftExam::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
        if ($locked->isFrozen()) {
            throw new GenerationRefused('draft_published', 'تم نشر هذه المسودة؛ عدّل الامتحان من محرر الامتحانات.', 409);
        }

        return $locked;
    }

    public static function ensureLive(DraftQuestion $question): void
    {
        if (! $question->state->isLive()) {
            throw new GenerationRefused('question_not_live', 'هذا السؤال محذوف أو تم استبداله.', 409);
        }
    }
}
