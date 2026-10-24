<?php

declare(strict_types=1);

namespace Modules\Courses\Http\Requests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Rule;

class InstructorsRequest extends CourseRequest
{
    public function authorize(): bool
    {
        return $this->isStaff() && $this->user()->can('assignInstructors', $this->course());
    }

    protected function failedAuthorization(): never
    {
        throw new AuthorizationException('تعيين المعلمين متاح للمشرف فقط.');
    }

    public function rules(): array
    {
        return [
            'instructor_ids' => ['required', 'array', 'min:1'],
            'instructor_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where('role', 'teacher')],
        ];
    }
}
