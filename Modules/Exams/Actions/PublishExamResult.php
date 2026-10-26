<?php

declare(strict_types=1);

namespace Modules\Exams\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Exams\Enums\ExamResultStatus;
use Modules\Exams\Events\ExamResultPublished;
use Modules\Exams\Exceptions\ExamResultLocked;
use Modules\Exams\Models\ExamResult;

/** draft -> published, once (409 after), then the student and parent are told (after commit). */
final class PublishExamResult
{
    public function handle(User $actor, ExamResult $result): ExamResult
    {
        return DB::transaction(function () use ($actor, $result) {
            $locked = ExamResult::query()->whereKey($result->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isDraft()) {
                throw ExamResultLocked::alreadyPublished();
            }
            $locked->update(['status' => ExamResultStatus::Published, 'published_at' => now(), 'published_by' => $actor->id]);
            DB::afterCommit(fn () => event(new ExamResultPublished($locked->id, $actor->id)));

            return $locked;
        });
    }
}
