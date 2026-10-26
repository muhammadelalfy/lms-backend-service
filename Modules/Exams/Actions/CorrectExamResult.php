<?php

declare(strict_types=1);

namespace Modules\Exams\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Models\User;
use Modules\Exams\DTOs\CorrectionData;
use Modules\Exams\Enums\ExamResultStatus;
use Modules\Exams\Events\ExamResultCorrected;
use Modules\Exams\Exceptions\ExamResultLocked;
use Modules\Exams\Models\ExamResult;
use Modules\Exams\Models\ExamResultCorrection;

/**
 * The audited correction of a published (or already corrected) result: in one transaction the result
 * changes, one audit row per changed field is written (old value, new value, reason, who, when, a
 * shared group id) and the result becomes `corrected`. Repeatable; rows are only ever appended.
 * The student (both students, when the result moves to another one) is told after commit.
 */
final class CorrectExamResult
{
    /** @return array{result: ExamResult, group: string} */
    public function handle(User $actor, ExamResult $result, CorrectionData $data): array
    {
        return DB::transaction(function () use ($actor, $result, $data) {
            $locked = ExamResult::query()->whereKey($result->id)->lockForUpdate()->firstOrFail();
            if ($locked->isDraft()) {
                throw ExamResultLocked::stillDraft();
            }

            $changes = [];
            foreach ($data->changes as $field => $value) {
                $old = $this->stored($locked, $field);
                $new = $this->normalised($field, $value);
                if ($old !== $new) {
                    $changes[$field] = ['old' => $old, 'new' => $new];
                }
            }
            if ($changes === []) {
                throw ValidationException::withMessages(['changes' => 'لم يتغير أي حقل؛ أرسل قيمة جديدة واحدة على الأقل للدرجة أو الدرجة العظمى أو العنوان أو التاريخ أو الطالب.']);
            }

            $now = now();
            $group = (string) Str::uuid();
            $previousStudent = (int) $locked->student_id;
            ExamResultCorrection::query()->insert(array_map(fn (string $field) => [
                'exam_result_id' => $locked->id,
                'correction_group_id' => $group,
                'field' => $field,
                'old_value' => $changes[$field]['old'],
                'new_value' => $changes[$field]['new'],
                'reason' => $data->reason,
                'corrected_by' => $actor->id,
                'corrected_at' => $now,
            ], array_keys($changes)));

            $locked->update([
                ...array_map(fn (array $c) => $c['new'], $changes),
                'status' => ExamResultStatus::Corrected,
                'last_corrected_at' => $now,
            ]);
            $locked->refresh();
            $students = array_values(array_unique([$previousStudent, (int) $locked->student_id]));
            DB::afterCommit(fn () => event(new ExamResultCorrected($locked->id, $group, $actor->id, $students)));

            return ['result' => $locked, 'group' => $group];
        });
    }

    private function stored(ExamResult $result, string $field): ?string
    {
        $value = $result->getAttribute($field);

        return match (true) {
            $value === null => null,
            $field === 'taken_at' => Carbon::parse($value)->toDateString(),
            default => (string) $value,
        };
    }

    private function normalised(string $field, mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            $field === 'taken_at' => Carbon::parse($value)->toDateString(),
            $field === 'title' => trim((string) $value),
            default => (string) (int) $value,
        };
    }
}
