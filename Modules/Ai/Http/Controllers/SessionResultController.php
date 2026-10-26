<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Ai\Http\Requests\SessionResultRequest;
use Modules\Ai\Models\AnswerGrade;
use Modules\Exams\Models\ExamResult;
use Modules\Exams\Models\ExamSession;

/**
 * What a student sees of a submitted exam: "جاري التصحيح" and no score while any written answer waits
 * for the teacher or the result is still a draft; the score (and per-answer feedback) only once the
 * result is published (S6). Students never see AI-only output: the feedback shown is the teacher's
 * final feedback.
 */
class SessionResultController extends Controller
{
    public function show(SessionResultRequest $request, ExamSession $session)
    {
        $result = ExamResult::query()->where('session_id', $session->id)->first();
        $visible = $result !== null && $result->status->isVisible();
        $staff = $request->user()->isAnyRole('admin', 'teacher');

        $status = match (true) {
            $visible => $result->status->value,
            $session->grading_state === null => 'not_graded',
            default => 'pending_review',
        };
        $answers = ($visible || $staff) && $session->grading_state !== null
            ? AnswerGrade::query()->where('session_id', $session->id)->orderBy('question_id')->get(['question_id', 'final_score', 'final_feedback'])
                ->map(fn (AnswerGrade $g) => ['question_id' => $g->question_id, 'score' => (float) $g->final_score, 'feedback' => $g->final_feedback])->all()
            : [];
        $showScore = $visible || ($staff && $result !== null);

        return response()->json(['data' => [
            'session_id' => $session->id,
            'template_id' => $session->template_id,
            'grading_state' => $session->grading_state,
            'status' => $status,
            'message' => $status === 'pending_review' ? 'جاري التصحيح' : null,
            'exam_result_id' => $showScore ? $result->id : null,
            'score' => $showScore ? (float) ($result->score_exact ?? $result->score) : null,
            'max_score' => $showScore ? $result->max_score : null,
            'published_at' => $visible ? $result->published_at?->toIso8601String() : null,
            'last_corrected_at' => $visible ? $result->last_corrected_at?->toIso8601String() : null,
            'answers' => $answers,
        ]]);
    }
}
