<?php

declare(strict_types=1);

namespace Modules\Courses\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Modules\Courses\Enums\CourseKind;
use Modules\Courses\Enums\OutlineStyle;
use Modules\Courses\Enums\Pricing;
use Modules\Courses\Enums\SequencingMode;

trait DescribesCourse
{
    /** @return array<string, mixed> */
    protected function courseRules(bool $partial): array
    {
        return [
            'title' => [$partial ? 'sometimes' : 'required', 'string', 'max:200'],
            'kind' => ['sometimes', Rule::enum(CourseKind::class)],
            'summary' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:20000'],
            'goals' => ['nullable', 'array', 'max:20'],
            'goals.*' => ['string', 'max:300'],
            'target_audience' => ['nullable', 'string', 'max:500'],
            'estimated_duration_minutes' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'access_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'visible_from' => ['nullable', 'date'],
            'visible_until' => ['nullable', 'date', 'after:visible_from'],
            'is_featured' => ['sometimes', 'boolean'],
            'season_name' => ['nullable', 'string', 'max:100'],
            'sequencing_mode' => ['sometimes', Rule::enum(SequencingMode::class)],
            'outline_style' => ['sometimes', Rule::enum(OutlineStyle::class)],
            'pricing' => ['sometimes', Rule::enum(Pricing::class)],
            'price_amount' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'discounted_price_amount' => ['nullable', 'integer', 'min:1', 'lt:price_amount'],
        ];
    }
}
