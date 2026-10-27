<?php

declare(strict_types=1);

namespace Modules\Ai\Actions;

use Modules\Ai\Models\QuestionRubric;
use Modules\Exams\Models\ExamQuestion;

/**
 * The model answer and marking scheme of an exam question (manual exams, or editing an AI-published
 * one), kept beside `exam_questions` so the exam runner never sees it. Applies to sessions submitted
 * afterwards; answers already queued for grading keep the rubric they were sent with.
 */
final class SaveQuestionRubric
{
    /** @param array{model_answer?: string|null, rubric?: array|null} $data the whole rubric (PUT replaces it) */
    public function handle(ExamQuestion $question, array $data): QuestionRubric
    {
        $rubric = QuestionRubric::query()->firstOrNew(['question_id' => $question->id]);
        $criteria = $data['rubric']['criteria'] ?? null;
        $rubric->fill([
            'model_answer' => isset($data['model_answer']) ? trim($data['model_answer']) : null,
            'rubric' => $criteria === null ? null : [
                'criteria' => array_map(fn (array $c) => ['id' => (string) $c['id'], 'text' => trim((string) $c['text']), 'marks' => (float) $c['marks']], $criteria),
                'accept_alternatives' => array_values($data['rubric']['accept_alternatives'] ?? []),
            ],
            'source' => 'manual',
        ]);
        $rubric->save();

        return $rubric;
    }
}
