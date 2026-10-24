<?php

declare(strict_types=1);

namespace Modules\Courses\Http\Requests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Rule;
use Modules\Courses\Enums\ContentStatus;

class ChangeStatusRequest extends CourseRequest
{
    public function authorize(): bool
    {
        return $this->isStaff() && $this->user()->can('publish', $this->course());
    }

    protected function failedAuthorization(): never
    {
        throw new AuthorizationException('لا تملك صلاحية نشر هذه الدورة.');
    }

    public function rules(): array
    {
        return ['status' => ['required', Rule::enum(ContentStatus::class)]];
    }

    public function target(): ContentStatus
    {
        return ContentStatus::from($this->validated('status'));
    }
}
