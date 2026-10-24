<?php

declare(strict_types=1);

namespace Modules\Courses\Actions;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Courses\Enums\ContentStatus;
use Modules\Courses\Exceptions\ContentRuleViolation;
use Modules\Courses\Models\Course;

final class ReorderContent
{
    public function __construct(private readonly RecordCourseVersion $versions) {}

    /** @param  list<int>  $orderedIds  every current child, once, in the new order */
    public function handle(HasMany $children, array $orderedIds, ?User $actor = null): void
    {
        DB::transaction(function () use ($children, $orderedIds, $actor): void {
            $parent = $children->getParent();
            $parent->newQuery()->whereKey($parent->getKey())->lockForUpdate()->value($parent->getKeyName());

            $current = $children->getQuery()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $requested = array_map('intval', $orderedIds);
            sort($requested);
            if ($current !== $requested) {
                throw ContentRuleViolation::reorderMismatch();
            }

            foreach (array_values($orderedIds) as $index => $id) {
                $children->getRelated()->newQuery()->whereKey($id)->update(['position' => $index + 1]);
            }

            // Reordering what learners see is a change to the course.
            if ($children->getQuery()->clone()->where('status', ContentStatus::Published)->exists()) {
                $this->versions->touch($parent instanceof Course ? $parent : $parent->course, 'content', $actor);
            }
        });
    }
}
