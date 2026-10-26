<?php

declare(strict_types=1);

namespace Modules\Ai\Policies;

use Modules\Ai\Models\Material;
use Modules\Auth\Models\User;

/** Teachers work with their own materials, the center's admin with all of them; nobody else (assistants come later). */
final class MaterialPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAnyRole('admin', 'teacher');
    }

    public function create(User $user): bool
    {
        return $user->isAnyRole('admin', 'teacher');
    }

    public function view(User $user, Material $material): bool
    {
        return $user->isAnyRole('admin') || ($user->isAnyRole('teacher') && $material->owner_user_id === $user->id);
    }

    public function delete(User $user, Material $material): bool
    {
        return $this->view($user, $material);
    }
}
