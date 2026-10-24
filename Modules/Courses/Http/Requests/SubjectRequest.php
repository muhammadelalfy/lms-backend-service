<?php

declare(strict_types=1);

namespace Modules\Courses\Http\Requests;

class SubjectRequest extends CourseRequest
{
    public function authorize(): bool
    {
        return $this->managesCourse();
    }

    public function rules(): array
    {
        return [
            'title' => [$this->isMethod('PATCH') ? 'sometimes' : 'required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
