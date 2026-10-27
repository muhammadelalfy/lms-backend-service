<?php

declare(strict_types=1);

namespace Modules\Ai\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Ai\Enums\SuggestionStatus;
use Modules\Ai\Jobs\SuggestGradeJob;
use Modules\Ai\Models\GradingSuggestion;
use Modules\Ai\Models\QuestionRubric;
use Modules\Ai\Services\UnitBudget;
use Modules\Ai\Support\GradingNote;
use Modules\Exams\Models\ExamSession;
use Modules\Exams\Models\ExamSessionAnswer;
use Modules\Tenancy\Models\School;

/**
 * After a session is submitted: when its exam has rubrics (AI-published, or attached by the teacher to
 * a manual exam), every written answer becomes an item of the teacher's grading queue and the session
 * is `pending_review` until each one is decided. Answers with a rubric go to the AI (one queued job
 * each) when the plan allows it and the month's units are not used up; blank answers get 0 without
 * any AI call; the rest wait for a manual grade. An exam without rubrics is left exactly as before.
 * Idempotent: a second run adds nothing (unique session + question).
 */
final class PrepareSessionGrading
{
    private const AUTO_TYPES = ['mcq', 'true_false'];

    public function __construct(private readonly UnitBudget $budget) {}

    public function handle(int $sessionId): void
    {
        $session = ExamSession::query()->with(['template.questions', 'answers'])->find($sessionId);
        if ($session === null || $session->status !== 'submitted' || $session->grading_state !== null) {
            return;
        }
        $written = $session->template->questions->reject(fn ($q) => in_array($q->type, self::AUTO_TYPES, true));
        if ($written->isEmpty()) {
            return;
        }
        $rubrics = QuestionRubric::query()->whereIn('question_id', $written->pluck('id'))->get()->keyBy('question_id');
        if ($rubrics->isEmpty()) {
            return;
        }

        $blocked = match (true) {
            ! $this->aiEnabled() => GradingNote::PLAN_FEATURE,
            $this->budget->snapshot()['blocked'] => GradingNote::CAP_REACHED,
            default => null,
        };
        $answers = $session->answers->keyBy('question_id');
        $now = now();
        $rows = [];
        foreach ($written as $question) {
            /** @var ExamSessionAnswer|null $answer */
            $answer = $answers->get($question->id);
            $blank = $answer === null || trim((string) $answer->answer) === '';
            $rubric = $rubrics->get($question->id);
            [$status, $note] = match (true) {
                $blank => [SuggestionStatus::Empty, GradingNote::EMPTY_ANSWER],
                $rubric === null => [SuggestionStatus::Skipped, GradingNote::NO_RUBRIC],
                $blocked !== null => [SuggestionStatus::Skipped, $blocked],
                default => [SuggestionStatus::Queued, null],
            };
            $rows[] = [
                'session_id' => $session->id,
                'question_id' => $question->id,
                'session_answer_id' => $answer?->id,
                'template_id' => $session->template_id,
                'status' => $status->value,
                'failure_code' => $note,
                'max_score' => (int) $question->points,
                'suggested_score' => $blank ? 0 : null,
                'feedback' => $blank ? GradingNote::message(GradingNote::EMPTY_ANSWER) : null,
                'confidence' => $blank ? 1 : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($session, $rows) {
            $claimed = ExamSession::query()->whereKey($session->id)->whereNull('grading_state')->update(['grading_state' => 'pending_review']);
            if ($claimed === 0) {
                return;
            }
            GradingSuggestion::query()->insertOrIgnore($rows);
            DB::afterCommit(function () use ($session) {
                $queued = GradingSuggestion::query()->where('session_id', $session->id)->where('status', SuggestionStatus::Queued->value)->pluck('id');
                if ($queued->isNotEmpty()) {
                    Queue::bulk($queued->map(fn (int $id) => new SuggestGradeJob($id))->all(), '', (string) config('ai.queue'));
                }
            });
        });
    }

    private function aiEnabled(): bool
    {
        $school = tenant();

        return $school instanceof School && $school->feature('ai_exams') === true;
    }
}
