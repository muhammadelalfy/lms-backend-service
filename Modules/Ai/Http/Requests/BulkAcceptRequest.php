<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests;

/**
 * Bulk accept is always explicit: the ids, or one exam + a confidence threshold + the count the
 * teacher confirmed.
 */
class BulkAcceptRequest extends GradingRequest
{
    public function rules(): array
    {
        $max = (int) config('ai.grading.bulk_max');

        return [
            'ids' => ['required_without:exam_id', 'prohibits:exam_id', 'array', 'min:1', "max:{$max}"],
            'ids.*' => ['integer', 'distinct'],
            'exam_id' => ['required_without:ids', 'integer'],
            'min_confidence' => ['nullable', 'numeric', 'between:0,1'],
            'count' => ['required_with:exam_id', 'integer', 'min:1', "max:{$max}"],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required_without' => 'حدّد الإجابات، أو الامتحان مع عدد الإجابات الذي أكّدته.',
            'count.required_with' => 'أكّد عدد الإجابات التي ستُعتمد.',
        ];
    }

    public function minConfidence(): float
    {
        return (float) ($this->validated('min_confidence') ?? config('ai.grading.bulk_accept_min_confidence'));
    }
}
