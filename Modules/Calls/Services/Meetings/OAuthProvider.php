<?php

namespace Modules\Calls\Services\Meetings;

use Modules\Calls\Models\ProviderAccount;
use Modules\Auth\Models\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Connects a person's Google or Zoom account so the app can create a Meet or
 * Zoom meeting for them. Authorisation-code flow: the app opens the URL in a
 * browser, the provider redirects to our callback, and the tokens are kept
 * encrypted. Without client credentials the provider is simply unavailable
 * and teachers paste a link instead.
 */
class OAuthProvider
{
    public const PROVIDERS = ['google', 'zoom'];

    public function isConfigured(string $provider): bool
    {
        return ! empty(config("services.{$provider}.client_id")) && ! empty(config("services.{$provider}.client_secret"));
    }

    public function authorizeUrl(User $user, string $provider): string
    {
        abort_unless($this->isConfigured($provider), 503, 'ربط هذا الحساب غير مفعّل على الخادم.');
        // The state proves, when the browser comes back, who started this, in which school and for what.
        $state = Crypt::encryptString((string) json_encode(['school' => tenant('id'), 'user' => $user->id, 'provider' => $provider, 'exp' => time() + 600]));
        $redirect = config("services.{$provider}.redirect");

        return match ($provider) {
            'google' => 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
                'client_id' => config('services.google.client_id'), 'redirect_uri' => $redirect, 'response_type' => 'code',
                'scope' => 'https://www.googleapis.com/auth/calendar.events', 'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state,
            ]),
            'zoom' => 'https://zoom.us/oauth/authorize?'.http_build_query([
                'response_type' => 'code', 'client_id' => config('services.zoom.client_id'), 'redirect_uri' => $redirect, 'state' => $state,
            ]),
        };
    }

    /** The school that started the flow, read from the state; null when the state is not ours. */
    public function schoolOf(string $state): ?string
    {
        $claims = json_decode((string) $this->decrypt($state), true);

        return is_array($claims) && is_string($claims['school'] ?? null) ? $claims['school'] : null;
    }

    /** Finishes the flow: swaps the code for tokens and stores them. */
    public function complete(string $provider, string $code, string $state): ProviderAccount
    {
        $claims = json_decode((string) $this->decrypt($state), true);
        abort_unless(is_array($claims) && ($claims['provider'] ?? null) === $provider && ($claims['exp'] ?? 0) >= time(), 403, 'انتهت صلاحية طلب الربط.');

        $tokens = $this->tokenRequest($provider, [
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => config("services.{$provider}.redirect"),
        ]);

        return ProviderAccount::query()->updateOrCreate(
            ['user_id' => $claims['user'], 'provider' => $provider],
            $this->fields($tokens),
        );
    }

    /** An access token that works now, refreshed when it is about to expire. */
    public function freshToken(ProviderAccount $account): string
    {
        if ($account->expires_at !== null && $account->expires_at->subMinute()->isPast()) {
            if ($account->refresh_token === null) {
                throw new HttpException(422, 'انتهى ربط الحساب. اربطه من جديد.');
            }
            $tokens = $this->tokenRequest($account->provider, ['grant_type' => 'refresh_token', 'refresh_token' => $account->refresh_token]);
            $account->update($this->fields($tokens, $account->refresh_token));
        }

        return $account->access_token;
    }

    private function tokenRequest(string $provider, array $form): array
    {
        $client = Http::asForm()->timeout(10);
        try {
            return match ($provider) {
                'google' => $client->post('https://oauth2.googleapis.com/token', $form + [
                    'client_id' => config('services.google.client_id'), 'client_secret' => config('services.google.client_secret'),
                ])->throw()->json(),
                'zoom' => $client->withBasicAuth(config('services.zoom.client_id'), config('services.zoom.client_secret'))
                    ->post('https://zoom.us/oauth/token', $form)->throw()->json(),
            };
        } catch (RequestException) {
            throw new HttpException(422, 'تعذر ربط الحساب. حاول من جديد.');
        }
    }

    private function fields(array $tokens, ?string $keepRefresh = null): array
    {
        return [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? $keepRefresh,
            'expires_at' => isset($tokens['expires_in']) ? now()->addSeconds((int) $tokens['expires_in']) : null,
        ];
    }

    private function decrypt(string $state): ?string
    {
        try {
            return Crypt::decryptString($state);
        } catch (\Throwable) {
            return null;
        }
    }
}
