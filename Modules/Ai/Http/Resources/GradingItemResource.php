<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Ai\Models\GradingSuggestion;
use Modules\Ai\Support\GradingNote;

/**
 * One item of the teacher's grading queue: the question (with model answer and rubric), the student's
 * answer, the AI suggestion when there is one, and the teacher's decision once made. Needs session.student,
 * template, question, answer, rubric and grade.decider loaded.
 *
 * @mixin GradingSuggestion
 */
class GradingItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $hasSuggestion = $this->status->hasSuggestion() && $this->suggested_score !== null;
        $grade = $this->grade;
        $student = $this->session?->student;

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'note_code' => $this->failure_code,
            'note' => GradingNote::message($this->failure_code),
            'needs_attention' => $this->needs_attention,
            'decided' => $grade !== null,
            'exam' => ['id' => $this->template_id, 'title' => $this->template?->title],
            'session_id' => $this->session_id,
            'submitted_at' => $this->session?->submitted_at?->toIso8601String(),
            'student' => $student ? ['id' => $student->id, 'name' => $student->name, 'group' => $student->group] : null,
            'question' => [
                'id' => $this->question_id,
                'type' => $this->question?->type,
                'prompt_html' => $this->question?->prompt_html,
                'points' => $this->max_score,
                'model_answer' => $this->rubric?->model_answer,
                'rubric' => $this->rubric?->rubric,
            ],
            'answer' => ['id' => $this->session_answer_id, 'text' => $this->answer?->answer],
            'suggestion' => $hasSuggestion ? [
                'suggested_score' => (float) $this->suggested_score,
                'max_score' => $this->max_score,
                'criteria' => $this->criteria_scores ?? [],
                'feedback' => $this->feedback,
                'reasoning' => $this->reasoning,
                'confidence' => $this->confidence,
                'injection_suspected' => $this->injection_suspected,
                'prompt_version' => $this->prompt_version,
                'model' => $this->model_id,
                'created_at' => $this->updated_at?->toIso8601String(),
            ] : null,
            'decision' => $grade === null ? null : [
                'id' => $grade->id,
                'decision' => $grade->decision->value,
                'final_score' => (float) $grade->final_score,
                'final_feedback' => $grade->final_feedback,
                'decided_by' => $grade->decider ? ['id' => $grade->decider->id, 'name' => $grade->decider->name] : null,
                'decided_at' => $grade->decided_at?->toIso8601String(),
            ],
        ];
    }
}
