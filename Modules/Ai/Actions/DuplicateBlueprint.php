<?php

declare(strict_types=1);

namespace Modules\Ai\Actions;

use Modules\Ai\Models\ExamBlueprint;
use Modules\Auth\Models\User;

/**
 * Copies a blueprint for the actor ("load template", "save as template", "duplicate"). The copy is the
 * actor's own. A copy of someone else's template drops its sources: those are the other teacher's
 * private materials, the copier picks their own.
 */
final class DuplicateBlueprint
{
    /** @param  array{title?: string, is_template?: bool, is_private?: bool}  $overrides */
    public function handle(User $actor, ExamBlueprint $source, array $overrides): ExamBlueprint
    {
        $copy = $source->replicate(['owner_user_id', 'created_at', 'updated_at']);
        $copy->owner_user_id = $actor->id;
        $copy->title = $overrides['title'] ?? $source->title;
        $copy->is_template = (bool) ($overrides['is_template'] ?? $source->is_template);
        $copy->is_private = (bool) ($overrides['is_private'] ?? ($source->owner_user_id === $actor->id && $source->is_private));
        if ($source->owner_user_id !== $actor->id && ! $actor->isAnyRole('admin')) {
            $copy->source_selection = [];
        }
        $copy->save();

        return $copy;
    }
}
