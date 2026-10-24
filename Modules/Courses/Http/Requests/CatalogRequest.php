<?php

declare(strict_types=1);

namespace Modules\Courses\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Courses\Enums\CourseKind;
use Modules\Courses\Enums\Pricing;

class CatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kind' => ['nullable', Rule::enum(CourseKind::class)],
            'pricing' => ['nullable', Rule::enum(Pricing::class)],
            'featured' => ['nullable', 'boolean'],
            'cursor' => ['nullable', 'string'],
        ];
    }
}
