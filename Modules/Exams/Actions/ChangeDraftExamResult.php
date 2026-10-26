<?php

declare(strict_types=1);

namespace Modules\Exams\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Exams\DTOs\ExamResultData;
use Modules\Exams\Exceptions\ExamResultLocked;
use Modules\Exams\Models\ExamResult;

/**
 * Edits or deletes a result while it is a draft. Once published it is never edited directly or
 * hard-deleted again (409): the admin's audited correction is the only way to change it.
 */
final class ChangeDraftExamResult
{
    public function update(ExamResult $result, ExamResultData $data): ExamResult
    {
        return DB::transaction(function () use ($result, $data) {
            $locked = $this->lockedDraft($result);
            $locked->update($data->values);

            return $locked;
        });
    }

    public function delete(ExamResult $result): void
    {
        DB::transaction(fn () => $this->lockedDraft($result)->delete());
    }

    private function lockedDraft(ExamResult $result): ExamResult
    {
        $locked = ExamResult::query()->whereKey($result->id)->lockForUpdate()->firstOrFail();
        if (! $locked->isDraft()) {
            throw ExamResultLocked::published();
        }

        return $locked;
    }
}
