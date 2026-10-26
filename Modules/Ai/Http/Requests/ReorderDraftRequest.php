<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests;

class ReorderDraftRequest extends ReviewDraftRequest
{
    public function rules(): array
    {
        return ['question_ids' => ['required', 'array', 'min:1', 'max:200'], 'question_ids.*' => ['integer', 'distinct']];
    }

    public function messages(): array
    {
        return ['question_ids.*' => 'ترتيب الأسئلة غير صالح.'];
    }
}
