<?php

declare(strict_types=1);

namespace Modules\Ai\Policies;

use Modules\Ai\Models\GenerationJob;
use Modules\Auth\Models\User;

/** A teacher follows, cancels and retries their own jobs; the admin all jobs of the center. */
final class GenerationJobPolicy
{
    public function create(User $user): bool
    {
        return $user->isAnyRole('admin', 'teacher');
    }

    public function view(User $user, GenerationJob $job): bool
    {
        return $user->isAnyRole('admin') || ($user->isAnyRole('teacher') && $job->requested_by === $user->id);
    }

    public function update(User $user, GenerationJob $job): bool
    {
        return $this->view($user, $job);
    }
}
