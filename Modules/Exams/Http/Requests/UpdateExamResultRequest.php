<?php

declare(strict_types=1);

namespace Modules\Exams\Http\Requests;

/** Editing a draft result (a published one answers 409 from the action). */
class UpdateExamResultRequest extends ExamResultRequest
{
    protected string $ability = 'update';

    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|max:180',
            'score' => 'sometimes|integer|min:0',
            'max_score' => 'sometimes|integer|min:1',
            'taken_at' => 'sometimes|date',
        ];
    }
}
