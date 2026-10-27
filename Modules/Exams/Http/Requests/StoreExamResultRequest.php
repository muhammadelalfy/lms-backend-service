<?php

declare(strict_types=1);

namespace Modules\Exams\Http\Requests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Exams\Models\ExamResult;

/**
 * Recording a result. Staff first (403), then the fields (422), then whether this teacher may write
 * for this student (403), the order the endpoint always answered in. `status` is optional: without
 * it the result is published at once (what the published apps expect); `draft` keeps it private.
 */
class StoreExamResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAnyRole('admin', 'teacher');
    }

    public function rules(): array
    {
        return [
            'student_id' => 'required|exists:students,id',
            'title' => 'required|string|max:180',
            'score' => 'required|integer|min:0',
            'max_score' => 'required|integer|min:1',
            'taken_at' => 'required|date',
            'status' => ['sometimes', Rule::in(['draft', 'published'])],
        ];
    }

    protected function passedValidation(): void
    {
        if (! $this->user()->can('create', [ExamResult::class, $this->validated()])) {
            throw new AuthorizationException('لا تملك صلاحية هذا الطالب.');
        }
    }
}
