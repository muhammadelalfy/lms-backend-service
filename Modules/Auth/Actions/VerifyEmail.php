<?php

declare(strict_types=1);

namespace Modules\Auth\Actions;

use Modules\Auth\Models\User;
use Modules\Tenancy\Models\School;

/** Marks an email confirmed; confirming twice changes nothing. An admin's confirmation confirms the center. */
final class VerifyEmail
{
    /** @return bool whether this call confirmed it (false = it already was) */
    public function handle(?School $school, User $user): bool
    {
        $first = $user->email_verified_at === null;
        if ($first) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
        if ($school !== null && $user->isAnyRole('admin') && $school->owner_email_verified_at === null) {
            $school->forceFill(['owner_email_verified_at' => now()->toIso8601String()])->save();
        }

        return $first;
    }
}
