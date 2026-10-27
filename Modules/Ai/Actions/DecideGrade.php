<?php

declare(strict_types=1);

namespace Modules\Ai\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Ai\DTOs\GradeDecisionData;
use Modules\Ai\Enums\GradeDecision;
use Modules\Ai\Events\GradeDecided;
use Modules\Ai\Exceptions\GradeConflict;
use Modules\Ai\Models\AnswerGrade;
use Modules\Ai\Models\GradingSuggestion;
use Modules\Ai\Services\SessionResults;
use Modules\Auth\Models\User;
use Modules\Exams\Models\ExamResult;
use Modules\Exams\Models\ExamSession;

/**
 * The teacher's grade for one written answer: final score and feedback, who decided, when and how.
 * The AI suggestion row is never touched. Sending the same decision again returns it (200); a
 * different one is refused (409) and points to the exam result (edit while draft, audited correction
 * once published). Deciding the last answer of a session records its draft result in the same
 * transaction (row lock on the session, as `submit` does).
 */
final class DecideGrade
{
    public function __construct(private readonly SessionResults $results) {}

    /** @return array{grade: AnswerGrade, created: bool} */
    public function handle(User $actor, GradingSuggestion $suggestion, GradeDecisionData $data): array
    {
        return DB::transaction(function () use ($actor, $suggestion, $data) {
            ExamSession::query()->whereKey($suggestion->session_id)->lockForUpdate()->first();
            $item = GradingSuggestion::query()->with('grade')->findOrFail($suggestion->id);
            [$score, $feedback] = $this->resolve($item, $data);

            if ($item->grade !== null) {
                $same = $item->grade->decision === $data->decision
                    && abs((float) $item->grade->final_score - $score) < 0.001
                    && (string) $item->grade->final_feedback === (string) $feedback;
                if (! $same) {
                    throw GradeConflict::alreadyDecided(ExamResult::query()->where('session_id', $item->session_id)->value('id'));
                }

                return ['grade' => $item->grade, 'created' => false];
            }

            $grade = AnswerGrade::create([
                'suggestion_id' => $item->id,
                'session_id' => $item->session_id,
                'question_id' => $item->question_id,
                'session_answer_id' => $item->session_answer_id,
                'final_score' => $score,
                'final_feedback' => $feedback,
                'decision' => $data->decision,
                'decided_by' => $actor->id,
                'decided_at' => now(),
            ]);
            $reviewed = $this->results->finalize([$item->session_id], $actor->id);
            DB::afterCommit(fn () => event(new GradeDecided([$grade->id], $actor->id, $reviewed)));

            return ['grade' => $grade, 'created' => true];
        });
    }

    /** @return array{0: float, 1: string|null} the final score and feedback this decision means */
    private function resolve(GradingSuggestion $item, GradeDecisionData $data): array
    {
        $hasSuggestion = $item->status->hasSuggestion() && $item->suggested_score !== null;
        if ($data->decision !== GradeDecision::Manual && ! $hasSuggestion) {
            throw ValidationException::withMessages(['decision' => 'لا يوجد اقتراح لهذه الإجابة؛ أدخل الدرجة يدوياً (manual).']);
        }

        if ($data->decision === GradeDecision::Accepted) {
            $score = (float) $item->suggested_score;
            if ($data->finalScore !== null && abs($data->finalScore - $score) >= 0.001) {
                throw ValidationException::withMessages(['final_score' => 'القبول يعني درجة الاقتراح نفسها؛ اختر "تعديل" (edited) لتغيير الدرجة.']);
            }
            $feedback = $data->feedbackSent ? $data->finalFeedback : $item->feedback;
        } else {
            if ($data->finalScore === null) {
                throw ValidationException::withMessages(['final_score' => 'الدرجة النهائية مطلوبة.']);
            }
            $score = $data->finalScore;
            $feedback = $data->feedbackSent ? $data->finalFeedback : ($data->decision === GradeDecision::Edited ? $item->feedback : null);
        }

        if ($score < 0 || $score > $item->max_score + 0.0001) {
            throw ValidationException::withMessages(['final_score' => "الدرجة يجب أن تكون بين 0 و{$item->max_score}."]);
        }
        if (abs($score * 4 - round($score * 4)) > 0.0001) {
            throw ValidationException::withMessages(['final_score' => 'الدرجة تكون بمضاعفات الربع (مثل 2.25 أو 2.5).']);
        }

        return [round($score * 4) / 4, $feedback === '' ? null : $feedback];
    }
}
