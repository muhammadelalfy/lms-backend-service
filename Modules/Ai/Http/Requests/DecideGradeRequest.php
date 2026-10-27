<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Ai\Enums\GradeDecision;

class DecideGradeRequest extends GradingRequest
{
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(GradeDecision::values())],
            'final_score' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'final_feedback' => ['nullable', 'string', 'max:3000'],
        ];
    }

    public function messages(): array
    {
        return ['decision.in' => 'نوع القرار يكون: accepted أو edited أو overridden أو manual.'];
    }
}
