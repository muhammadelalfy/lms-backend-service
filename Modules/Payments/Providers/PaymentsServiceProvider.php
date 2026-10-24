<?php

namespace Modules\Payments\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Payments\Models\Payment;
use Modules\Payments\Observers\PaymentObserver;
use Modules\Payments\Policies\PaymentPolicy;

final class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Route::middleware(['api', 'school'])->prefix('api')->group(__DIR__.'/../routes/api.php');
        Gate::policy(Payment::class, PaymentPolicy::class);
        Payment::observe(PaymentObserver::class);
    }
}
