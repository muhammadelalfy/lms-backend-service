<?php

namespace Modules\PluginStore\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\PluginStore\Models\InstalledModule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a route behind the current school's own plugin entitlement.
 *
 * Every catalogue module's code always ships with the app and its
 * ServiceProvider always boots (ADR 0001) — "is the provider loaded" is no
 * longer a safe gate, because it would be true for every school whether or
 * not that school purchased and installed the plugin. Whether *this*
 * school may use the plugin is purely the already tenant-scoped
 * `installed_modules` row in its own database.
 *
 * Usage on a route that a catalogue plugin module owns:
 *   Route::middleware(EnsureModuleInstalled::class . ':AttendanceInsights')->group(...);
 */
final class EnsureModuleInstalled
{
    public function handle(Request $request, Closure $next, string $moduleName): Response
    {
        $installed = InstalledModule::query()
            ->where('module_name', $moduleName)
            ->where('status', 'installed')
            ->exists();

        abort_unless($installed, 403, 'هذه الإضافة غير مفعّلة لمركزك.');

        return $next($request);
    }
}
