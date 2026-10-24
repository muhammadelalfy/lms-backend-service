<?php

namespace Modules\Tenancy\Providers;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Tenancy\Console\AdoptSchool;
use Modules\Tenancy\Console\CreateSchool;
use Modules\Tenancy\Console\DispatchToSchools;
use Modules\Tenancy\Console\ListSchools;
use Modules\Tenancy\Console\SetSchoolStatus;
use Modules\Tenancy\Http\Middleware\InitializeSchool;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Features\TenantConfig;
use Stancl\Tenancy\Jobs;
use Stancl\Tenancy\Listeners;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/**
 * Wires schools (tenants) into the application: what happens when one is
 * opened or closed, which request middleware finds the school from the
 * address, and the operator's routes and commands.
 */
final class SchoolsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->commands([CreateSchool::class, AdoptSchool::class, ListSchools::class, SetSchoolStatus::class, DispatchToSchools::class]);
    }

    public function boot(): void
    {
        $this->bootEvents();
        $this->mapRoutes();
        $this->prioritiseTenancyMiddleware();

        // An address that belongs to no school is answered, not a server error.
        InitializeTenancyByDomain::$onFail = fn () => response()->json(['message' => 'المركز غير موجود. تحقق من عنوان مركزك.', 'code' => 'unknown_school'], 404);

        // A school's own payment, SMS and WhatsApp settings replace the platform's while it is in use.
        TenantConfig::$storageToConfigMap = config('schools.settings', []);
    }

    private function bootEvents(): void
    {
        // Opening a school creates and migrates its database; closing it drops the database.
        // (Tests that use throwaway in-memory databases turn this off.)
        $provision = JobPipeline::make([
            Jobs\CreateDatabase::class,
            Jobs\MigrateDatabase::class,
        ])->send(fn (Events\TenantCreated $event) => $event->tenant)->shouldBeQueued(false)->toListener();
        Event::listen(Events\TenantCreated::class, fn ($event) => config('tenancy.provision_databases', true) ? $provision($event) : null);

        $drop = JobPipeline::make([
            Jobs\DeleteDatabase::class,
        ])->send(fn (Events\TenantDeleted $event) => $event->tenant)->shouldBeQueued(false)->toListener();
        Event::listen(Events\TenantDeleted::class, fn ($event) => config('tenancy.provision_databases', true) ? $drop($event) : null);

        Event::listen(Events\TenancyInitialized::class, Listeners\BootstrapTenancy::class);
        Event::listen(Events\TenancyEnded::class, Listeners\RevertToCentralContext::class);
    }

    private function mapRoutes(): void
    {
        $this->app->booted(function () {
            require __DIR__.'/../routes/central.php';
        });

        // What people of a school see and the admin edits (its look).
        Route::middleware(['api', 'school'])->prefix('api')->group(__DIR__.'/../routes/school.php');
    }

    private function prioritiseTenancyMiddleware(): void
    {
        // The school must be known before anything that reads its database, such as authentication.
        $kernel = $this->app[Kernel::class];
        foreach (array_reverse([PreventAccessFromCentralDomains::class, InitializeTenancyByDomain::class, InitializeSchool::class]) as $middleware) {
            $kernel->prependToMiddlewarePriority($middleware);
        }
    }
}
