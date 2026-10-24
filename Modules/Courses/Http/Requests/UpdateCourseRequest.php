<?php

declare(strict_types=1);

namespace Modules\Courses\Http\Requests;

use Modules\Courses\Http\Requests\Concerns\DescribesCourse;

class UpdateCourseRequest extends CourseRequest
{
    use DescribesCourse;

    public function authorize(): bool
    {
        return $this->managesCourse();
    }

    public function rules(): array
    {
        return $this->courseRules(partial: true);
    }
}
