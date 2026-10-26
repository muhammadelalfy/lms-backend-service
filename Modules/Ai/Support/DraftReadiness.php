<?php

declare(strict_types=1);

namespace Modules\Ai\Support;

use Illuminate\Support\Collection;
use Modules\Ai\Enums\QuestionState;
use Modules\Ai\Models\DraftExam;
use Modules\Ai\Models\DraftQuestion;

/** What stands between a draft and publishing it, as stable codes with the Arabic reason. */
final class DraftReadiness
{
    public const MESSAGES = [
        'published' => 'تم نشر هذه المسودة بالفعل.',
        'generating' => 'التوليد ما زال جارياً لهذه المسودة. انتظر حتى ينتهي.',
        'empty' => 'لا توجد أسئلة في المسودة.',
        'not_all_approved' => 'يجب اعتماد كل الأسئلة المتبقية قبل النشر.',
        'marks_mismatch' => 'مجموع درجات الأسئلة لا يساوي الدرجة الكلية للامتحان.',
    ];

    /**
     * @param  Collection<int, DraftQuestion>  $live  the draft's pending and approved questions
     * @return list<string> codes, in the order the teacher should fix them
     */
    public static function blockers(DraftExam $draft, Collection $live, bool $jobActive, bool $overrideTotal = false): array
    {
        $blockers = [];
        if ($draft->isFrozen()) {
            $blockers[] = 'published';
        }
        if ($jobActive) {
            $blockers[] = 'generating';
        }
        if ($live->isEmpty()) {
            $blockers[] = 'empty';
        }
        if ($live->contains(fn (DraftQuestion $q) => $q->state !== QuestionState::Approved)) {
            $blockers[] = 'not_all_approved';
        }
        if (! $overrideTotal && $live->isNotEmpty() && (int) $live->sum('marks') !== $draft->total_marks) {
            $blockers[] = 'marks_mismatch';
        }

        return $blockers;
    }
}
