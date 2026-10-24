<?php

use Illuminate\Support\Facades\Route;
use Modules\Tenancy\Http\Controllers\OperatorDashboardController;
use Modules\Tenancy\Http\Controllers\PlansController;
use Modules\Tenancy\Http\Controllers\SchoolController;
use Modules\Tenancy\Http\Controllers\SchoolLookupController;
use Modules\Tenancy\Http\Controllers\SignUpController;
use Modules\Tenancy\Http\Middleware\OperatorToken;

// The platform operator's API: only on the central domains, only with the operator's token.
foreach (config('tenancy.central_domains') as $domain) {
    Route::middleware(['api', OperatorToken::class])
        ->domain($domain)
        ->prefix('api/central')
        ->group(function () {
            Route::get('/schools', [SchoolController::class, 'index']);
            Route::post('/schools', [SchoolController::class, 'store']);
            Route::get('/schools/{school}', [SchoolController::class, 'show']);
            Route::patch('/schools/{school}', [SchoolController::class, 'update']);
            Route::post('/schools/{school}/domains', [SchoolController::class, 'addDomain']);
            Route::delete('/schools/{school}', [SchoolController::class, 'destroy']);
        });

    // The landing page's "enter your school code" box: public, central addresses only, and
    // limited per visitor so codes cannot be guessed in bulk.
    Route::middleware(['api', 'throttle:20,1'])
        ->domain($domain)
        ->get('api/school-lookup', SchoolLookupController::class);

    // The public plan catalog (landing, web app): central addresses only.
    Route::middleware(['api', 'throttle:60,1'])
        ->domain($domain)
        ->get('api/plans', PlansController::class);

    // The public sign-up: a trial academy of one's own. Few per visitor per hour.
    Route::middleware(['api', 'throttle:5,60'])
        ->domain($domain)
        ->post('api/signup', SignUpController::class);

    // The operator's dashboard page: only on the central addresses. It carries no data; it asks for the
    // operator token and then uses the API above.
    Route::domain($domain)->get('operator', OperatorDashboardController::class);
}
