<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests;

/** Ask the AI for one replacement question, optionally with an instruction ("اجعله أصعب"). Costs units. */
class RegenerateQuestionRequest extends ReviewDraftRequest
{
    public function rules(): array
    {
        return ['instruction' => ['nullable', 'string', 'max:500']];
    }

    public function messages(): array
    {
        return ['instruction.max' => 'التعليمات طويلة جداً (500 حرف كحد أقصى).'];
    }
}
