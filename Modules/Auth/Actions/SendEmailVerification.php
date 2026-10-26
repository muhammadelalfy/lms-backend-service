<?php

declare(strict_types=1);

namespace Modules\Auth\Actions;

use Illuminate\Support\Facades\URL;
use Modules\Auth\Models\User;
use Modules\Auth\Notifications\VerifyEmailNotification;
use Modules\Tenancy\Models\School;
use Throwable;

/**
 * Mails a center admin the signed link that confirms their email, valid `schools.email_verification.link_hours`.
 * The link points at the center's own address. Runs inside the center (its database holds the user).
 * A mail failure is reported and swallowed: the caller (sign-up, resend) still succeeds.
 */
final class SendEmailVerification
{
    public const ROUTE = 'center.email.verify';

    public function handle(School $school, User $user): bool
    {
        try {
            $user->notify(new VerifyEmailNotification($this->linkFor($school, $user), $school->name, $this->hours()));

            return true;
        } catch (Throwable $error) {
            report($error);

            return false;
        }
    }

    public function linkFor(School $school, User $user): string
    {
        $path = URL::temporarySignedRoute(self::ROUTE, now()->addHours($this->hours()), [
            'id' => $user->getKey(),
            'hash' => sha1((string) $user->email),
        ], absolute: false);

        return $this->origin($school).$path;
    }

    /** scheme://<center domain>[:port], with the scheme and port of the app's own URL. */
    private function origin(School $school): string
    {
        $app = parse_url((string) config('app.url')) ?: [];
        $scheme = $app['scheme'] ?? 'https';
        $port = isset($app['port']) ? ':'.$app['port'] : '';
        $domain = $school->domains()->orderBy('id')->value('domain') ?? $school->getTenantKey().'.'.config('tenancy.base_domain');

        return "{$scheme}://{$domain}{$port}";
    }

    private function hours(): int
    {
        return (int) config('schools.email_verification.link_hours', 48);
    }
}
