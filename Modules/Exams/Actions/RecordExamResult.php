<?php

declare(strict_types=1);

namespace Modules\Exams\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Exams\DTOs\ExamResultData;
use Modules\Exams\Enums\ExamResultStatus;
use Modules\Exams\Events\ExamResultPublished;
use Modules\Exams\Models\ExamResult;

/** Records a result, as a draft or published at once (then the student and parent are told, after commit). */
final class RecordExamResult
{
    public function handle(User $actor, ExamResultData $data): ExamResult
    {
        $published = $data->status === ExamResultStatus::Published;
        $result = ExamResult::create([
            ...$data->values,
            'recorded_by' => $actor->id,
            'status' => $published ? ExamResultStatus::Published : ExamResultStatus::Draft,
            'published_at' => $published ? now() : null,
            'published_by' => $published ? $actor->id : null,
        ]);
        if ($published) {
            DB::afterCommit(fn () => event(new ExamResultPublished($result->id, $actor->id)));
        }

        return $result;
    }
}
