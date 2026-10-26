<?php

declare(strict_types=1);

namespace Modules\Ai\Models;

use Illuminate\Database\Eloquent\Model;

/** Model answer and marking scheme of an `exam_questions` row; never sent to the exam runner. */
class QuestionRubric extends Model
{
    protected $table = 'exam_question_rubrics';

    protected $fillable = ['question_id', 'model_answer', 'rubric', 'source', 'ai_draft_question_id'];

    protected function casts(): array
    {
        return ['rubric' => 'array'];
    }
}
