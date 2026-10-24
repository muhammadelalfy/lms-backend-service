<?php

declare(strict_types=1);

namespace Modules\Courses\Actions;

use Modules\Courses\Models\Course;

final class SyncInstructors
{
    /** @param  list<int>  $instructorIds  in the order they are listed */
    public function handle(Course $course, array $instructorIds): void
    {
        $course->instructors()->sync(collect($instructorIds)->values()->mapWithKeys(fn (int $id, int $position) => [$id => ['position' => $position]])->all());
    }
}
