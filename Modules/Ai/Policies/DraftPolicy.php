<?php

declare(strict_types=1);

namespace Modules\Ai\Policies;

use Modules\Ai\Models\DraftExam;
use Modules\Auth\Models\User;

/** A teacher reviews and publishes their own drafts; the admin sees and may act on all drafts of the center. */
final class DraftPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAnyRole('admin', 'teacher');
    }

    public function view(User $user, DraftExam $draft): bool
    {
        return $user->isAnyRole('admin') || ($user->isAnyRole('teacher') && $draft->owner_user_id === $user->id);
    }

    public function review(User $user, DraftExam $draft): bool
    {
        return $this->view($user, $draft);
    }

    public function publish(User $user, DraftExam $draft): bool
    {
        return $this->view($user, $draft);
    }
}
