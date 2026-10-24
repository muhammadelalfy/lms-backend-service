<?php

namespace Modules\Ai\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Ai\Contracts\MaterialScanner;
use Modules\Ai\Models\Material;
use Modules\Ai\Policies\MaterialPolicy;

final class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MaterialScanner::class, fn ($app) => $app->make((string) config('ai.scanner')));
    }

    public function boot(): void
    {
        Route::middleware(['api', 'school'])->prefix('api')->group(__DIR__.'/../routes/api.php');
        Gate::policy(Material::class, MaterialPolicy::class);
    }
}
