<?php

declare(strict_types=1);

namespace Modules\Courses\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Courses\Http\Requests\Concerns\DescribesCourse;
use Modules\Courses\Models\Course;

class StoreCourseRequest extends CourseRequest
{
    use DescribesCourse;

    public function authorize(): bool
    {
        return $this->isStaff() && $this->user()->can('create', Course::class);
    }

    public function rules(): array
    {
        return $this->courseRules(partial: false) + [
            // Only an admin names the teachers; a teacher who creates a course teaches it.
            'instructor_ids' => [Rule::prohibitedIf(! $this->user()->isAnyRole('admin')), 'array', 'min:1'],
            'instructor_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where('role', 'teacher')],
        ];
    }
}
