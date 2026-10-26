<?php

namespace Modules\Auth\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class AuthServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // "Send the confirmation link again": 3 per account per hour, answered in Arabic.
        RateLimiter::for('email-verification', fn (Request $request) => Limit::perHour(3)
            ->by('email-verification:'.tenant('id').':'.$request->user()?->getAuthIdentifier())
            ->response(fn () => response()->json(['message' => 'طلبات كتير في وقت قصير، جرّب بعد شوية.'], 429)));

        Route::middleware(['api', 'school'])->prefix('api')->group(__DIR__.'/../routes/api.php');
    }
}
