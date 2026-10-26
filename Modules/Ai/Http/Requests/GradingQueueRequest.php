<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests;

use Illuminate\Validation\Rule;

class GradingQueueRequest extends GradingRequest
{
    public function rules(): array
    {
        return [
            'exam' => ['nullable', 'integer'],
            'question' => ['nullable', 'integer'],
            'class' => ['nullable', 'string', 'max:255'],
            'session' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['pending', 'decided', 'all'])],
            'sort' => ['nullable', Rule::in(['confidence', 'newest'])],
            'min_confidence' => ['nullable', 'numeric', 'between:0,1'],
            'needs_attention' => ['nullable', 'boolean'],
        ];
    }
}
