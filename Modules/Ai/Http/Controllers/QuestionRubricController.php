<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Ai\Actions\SaveQuestionRubric;
use Modules\Ai\Http\Requests\QuestionRubricRequest;
use Modules\Ai\Models\QuestionRubric;
use Modules\Exams\Models\ExamQuestion;

/** The model answer and rubric of an exam question ("إجابة نموذجية ومعيار التصحيح"). */
class QuestionRubricController extends Controller
{
    public function show(QuestionRubricRequest $request, ExamQuestion $question)
    {
        return response()->json(['data' => $this->present($question, QuestionRubric::query()->where('question_id', $question->id)->first())]);
    }

    public function update(QuestionRubricRequest $request, ExamQuestion $question, SaveQuestionRubric $save)
    {
        return response()->json(['data' => $this->present($question, $save->handle($question, $request->validated()))]);
    }

    private function present(ExamQuestion $question, ?QuestionRubric $rubric): array
    {
        return [
            'question_id' => $question->id,
            'points' => (int) $question->points,
            'model_answer' => $rubric?->model_answer,
            'rubric' => $rubric?->rubric,
            'source' => $rubric?->source,
            'updated_at' => $rubric?->updated_at?->toIso8601String(),
        ];
    }
}
