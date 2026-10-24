<?php

declare(strict_types=1);

namespace Modules\Courses\Http\Requests;

/** Reading a course's staff-only details: the people who run it, and nobody else. */
class CourseViewRequest extends CourseRequest
{
    public function authorize(): bool
    {
        return $this->isStaff() && $this->user()->can('view', $this->course());
    }

    public function rules(): array
    {
        return ['cursor' => ['nullable', 'string']];
    }
}
