<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Modules\Ai\Models\AnswerGrade;
use Modules\Ai\Models\GradingSuggestion;
use Modules\Exams\Enums\ExamResultStatus;
use Modules\Exams\Models\ExamResult;
use Modules\Exams\Models\ExamSession;
use Modules\Reports\Services\ReportVersion;

/**
 * Turns fully reviewed sessions into exam results. Once every written answer of a session has the
 * teacher's grade, its result is recorded as a **draft** (S6): MCQ / true-false marked against the key
 * plus the teacher's final scores, quarter marks kept in `score_exact`. The teacher (or admin) then
 * publishes it through the normal S6 flow; only then does the student see a score. Set-based (a bulk
 * accept may finish many sessions at once) and called inside the decision's transaction.
 */
final class SessionResults
{
    private const AUTO_TYPES = ['mcq', 'true_false'];

    /**
     * @param  list<int>  $sessionIds  sessions that just received grades
     * @return list<int> the sessions that became reviewed
     */
    public function finalize(array $sessionIds, int $actorId): array
    {
        if ($sessionIds === []) {
            return [];
        }
        $pending = GradingSuggestion::query()->whereIn('session_id', $sessionIds)->whereDoesntHave('grade')
            ->distinct()->pluck('session_id')->all();
        $complete = array_values(array_diff($sessionIds, $pending));
        if ($complete === []) {
            return [];
        }

        $sessions = ExamSession::query()->with(['template.questions', 'answers'])->whereIn('id', $complete)
            ->where('grading_state', 'pending_review')->get();
        if ($sessions->isEmpty()) {
            return [];
        }
        $written = AnswerGrade::query()->whereIn('session_id', $sessions->modelKeys())
            ->selectRaw('session_id, sum(final_score) as total')->groupBy('session_id')->pluck('total', 'session_id');
        $existing = ExamResult::query()->whereIn('session_id', $sessions->modelKeys())->pluck('session_id')->all();

        $now = now();
        $rows = [];
        foreach ($sessions as $session) {
            if (in_array($session->id, $existing, true)) {
                continue;
            }
            $answers = $session->answers->keyBy('question_id');
            $quarters = (int) round(((float) ($written[$session->id] ?? 0)) * 4);
            $max = 0;
            foreach ($session->template->questions as $question) {
                $max += (int) $question->points;
                if (in_array($question->type, self::AUTO_TYPES, true) && $this->correct($question->correct_answer, $answers->get($question->id)?->answer)) {
                    $quarters += 4 * (int) $question->points;
                }
            }
            $rows[] = [
                'student_id' => $session->student_id,
                'title' => $session->template->title,
                'score' => intdiv($quarters + 2, 4), // whole marks, half up
                'score_exact' => number_format($quarters / 4, 2, '.', ''),
                'max_score' => max(1, $max),
                'taken_at' => ($session->submitted_at ?? $now)->toDateString(),
                'recorded_by' => $actorId,
                'status' => ExamResultStatus::Draft->value,
                'session_id' => $session->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if ($rows !== []) {
            ExamResult::query()->insert($rows);
            ReportVersion::bump();
        }
        ExamSession::query()->whereIn('id', $sessions->modelKeys())->update(['grading_state' => 'reviewed']);

        return $sessions->modelKeys();
    }

    private function correct(?string $key, ?string $answer): bool
    {
        if ($key === null || $answer === null) {
            return false;
        }

        return mb_strtolower(trim($key)) === mb_strtolower(trim($answer));
    }
}
