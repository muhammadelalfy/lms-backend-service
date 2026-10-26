<?php

declare(strict_types=1);

namespace Modules\Exams\Listeners;

use Modules\Exams\Events\ExamResultCorrected;
use Modules\Exams\Events\ExamResultPublished;
use Modules\Exams\Models\ExamResult;
use Modules\Notifications\Services\StudentNotifier;

/** Tells the student and parent about a published result, and about a correction to one. */
final class NotifyExamResult
{
    public function __construct(private readonly StudentNotifier $notifier) {}

    public function published(ExamResultPublished $event): void
    {
        $result = ExamResult::query()->find($event->resultId);
        if ($result === null) {
            return;
        }
        $this->notifier->notify([$result->student_id], 'نتيجة اختبار جديدة', "نتيجة «{$result->title}»: {$result->score} من {$result->max_score}.", 'exam_result');
    }

    public function corrected(ExamResultCorrected $event): void
    {
        $result = ExamResult::query()->find($event->resultId);
        if ($result === null) {
            return;
        }
        $this->notifier->notify($event->studentIds, 'تصحيح نتيجة اختبار', "تم تصحيح نتيجة «{$result->title}»: {$result->score} من {$result->max_score}.", 'exam_result_corrected');
    }
}
