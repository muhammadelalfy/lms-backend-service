<?php

declare(strict_types=1);

namespace Modules\Courses\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Courses\Enums\ContentStatus;

class ListCoursesRequest extends CourseRequest
{
    public function authorize(): bool
    {
        return $this->isStaff();
    }

    public function rules(): array
    {
        return ['status' => ['nullable', Rule::enum(ContentStatus::class)], 'cursor' => ['nullable', 'string']];
    }
}
