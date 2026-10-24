<?php

declare(strict_types=1);

namespace Modules\Courses\DTOs;

use Modules\Courses\Support\SchoolTime;

/** What a request may change about a course (only the fields it sent), and who teaches it if it named them. */
final readonly class CourseData
{
    /** @param  array<string, mixed>  $attributes  @param  list<int>|null  $instructorIds */
    public function __construct(public array $attributes, public ?array $instructorIds = null) {}

    /** @param  array<string, mixed>  $validated */
    public static function fromValidated(array $validated): self
    {
        $instructors = $validated['instructor_ids'] ?? null;
        unset($validated['instructor_ids']);

        return new self(SchoolTime::convert($validated, ['starts_at', 'ends_at', 'visible_from', 'visible_until']), $instructors);
    }
}
