<?php

declare(strict_types=1);

namespace Modules\Courses\DTOs;

use Modules\Courses\Support\SchoolTime;

final readonly class LessonData
{
    /** @param  array<string, mixed>  $attributes */
    public function __construct(public array $attributes) {}

    /** @param  array<string, mixed>  $validated */
    public static function fromValidated(array $validated): self
    {
        // A live lesson without a stated provider is simply "another" kind of link.
        if (! empty($validated['live_link']) && empty($validated['live_provider'])) {
            $validated['live_provider'] = 'other';
        }

        return new self(SchoolTime::convert($validated, ['live_at']));
    }
}
