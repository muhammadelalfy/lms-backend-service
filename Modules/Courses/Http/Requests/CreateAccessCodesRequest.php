<?php

declare(strict_types=1);

namespace Modules\Courses\Http\Requests;

/** Staff who run the course ask for a batch of codes. */
class CreateAccessCodesRequest extends CourseRequest
{
    public function authorize(): bool
    {
        return $this->managesCourse();
    }

    public function rules(): array
    {
        return [
            'count' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'max_uses' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
