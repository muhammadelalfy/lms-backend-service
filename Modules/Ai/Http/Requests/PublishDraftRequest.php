<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Publish an approved draft into Exams. `status` is the new exam template's status (draft by default,
 * so the existing publish flow still decides when students see it); `override_total` accepts a marks
 * total different from the blueprint's.
 */
class PublishDraftRequest extends DraftRequest
{
    protected string $ability = 'publish';

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(['draft', 'published'])],
            'department_id' => ['nullable', 'integer', Rule::exists('exam_departments', 'id')],
            'override_total' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['status.in' => 'حالة الامتحان: مسودة أو منشور.', 'department_id.*' => 'القسم غير موجود.'];
    }
}
