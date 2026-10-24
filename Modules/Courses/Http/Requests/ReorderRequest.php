<?php

declare(strict_types=1);

namespace Modules\Courses\Http\Requests;

class ReorderRequest extends CourseRequest
{
    public function authorize(): bool
    {
        return $this->managesCourse();
    }

    public function rules(): array
    {
        return ['ids' => ['required', 'array'], 'ids.*' => ['integer', 'distinct']];
    }
}
