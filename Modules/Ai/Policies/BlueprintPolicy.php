<?php

declare(strict_types=1);

namespace Modules\Ai\Policies;

use Modules\Ai\Models\ExamBlueprint;
use Modules\Auth\Models\User;

/**
 * Teachers work with their own blueprints and may read (and copy) the center's shared templates;
 * the admin works with all of them. Nobody else (assistants come with S20).
 */
final class BlueprintPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAnyRole('admin', 'teacher');
    }

    public function create(User $user): bool
    {
        return $user->isAnyRole('admin', 'teacher');
    }

    public function view(User $user, ExamBlueprint $blueprint): bool
    {
        return $this->update($user, $blueprint)
            || ($user->isAnyRole('teacher') && $blueprint->is_template && ! $blueprint->is_private);
    }

    public function update(User $user, ExamBlueprint $blueprint): bool
    {
        return $user->isAnyRole('admin') || ($user->isAnyRole('teacher') && $blueprint->owner_user_id === $user->id);
    }

    public function delete(User $user, ExamBlueprint $blueprint): bool
    {
        return $this->update($user, $blueprint);
    }
}
