<?php

namespace Modules\PluginStore\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Modules\Auth\Models\User;
use Modules\PluginStore\Http\Middleware\EnsureModuleInstalled;
use Modules\PluginStore\Models\PluginProduct;
use Modules\Tenancy\Models\School;
use Tests\TestCase;

/**
 * Per ADR 0001, install/uninstall are a pure per-tenant DB flag flip against
 * `installed_modules` — the module's code always ships and boots, so there
 * is nothing left to extract, move or delete on disk, and nothing left for
 * one school's action to affect another school's.
 */
class PluginStoreTest extends TestCase
{
    private function enter(School $school): void
    {
        tenancy()->initialize($school);
        URL::forceRootUrl("http://{$school->id}.mudarisy.test");
    }

    private function createPlugin(array $overrides = []): PluginProduct
    {
        return PluginProduct::create(array_merge([
            'slug' => 'attendance-insights',
            'name' => 'تحليلات الحضور',
            'description' => 'وحدة تجريبية',
            'version' => '1.0.0',
            'module_name' => 'AttendanceInsights',
            'price' => 0,
            'is_active' => true,
        ], $overrides));
    }

    public function test_admin_can_purchase_and_install_a_plugin_as_a_pure_db_flag(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $plugin = $this->createPlugin();
        Sanctum::actingAs($admin);

        $this->assertFalse(File::isDirectory(base_path('Modules/AttendanceInsights')), 'install must never write to disk');

        $this->getJson('/api/plugins')->assertOk()->assertJsonPath('data.0.purchased', false);
        $this->postJson("/api/plugins/{$plugin->id}/purchase")->assertCreated();
        $this->postJson("/api/plugins/{$plugin->id}/install")
            ->assertOk()
            ->assertJsonPath('module.module_name', 'AttendanceInsights')
            ->assertJsonPath('module.status', 'installed');

        $this->assertDatabaseHas('installed_modules', ['module_name' => 'AttendanceInsights', 'status' => 'installed']);

        // No filesystem mutation, no change to the shared module registry.
        $this->assertFalse(File::isDirectory(base_path('Modules/AttendanceInsights')));
    }

    public function test_uninstall_only_flips_the_db_status_and_deletes_nothing_from_disk(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $plugin = $this->createPlugin();
        Sanctum::actingAs($admin);

        $admin->pluginPurchases()->create(['plugin_product_id' => $plugin->id, 'status' => 'completed', 'purchased_at' => now()]);
        $this->postJson("/api/plugins/{$plugin->id}/install")->assertOk();

        // Simulate a pre-existing on-disk module to prove uninstall never touches it.
        File::ensureDirectoryExists(base_path('Modules/AttendanceInsights'));
        File::put(base_path('Modules/AttendanceInsights/module.json'), '{"name":"AttendanceInsights"}');

        $this->deleteJson("/api/plugins/{$plugin->id}/install")->assertOk();

        $this->assertDatabaseHas('installed_modules', ['module_name' => 'AttendanceInsights', 'status' => 'uninstalled']);
        $this->assertTrue(File::isDirectory(base_path('Modules/AttendanceInsights')), 'uninstall must never delete anything from disk');

        File::deleteDirectory(base_path('Modules/AttendanceInsights'));
    }

    public function test_non_admin_cannot_install_a_plugin(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $plugin = $this->createPlugin(['slug' => 'blocked-plugin', 'module_name' => 'BlockedPlugin']);
        Sanctum::actingAs($teacher);

        $this->postJson("/api/plugins/{$plugin->id}/install")->assertForbidden();
        $this->assertDatabaseMissing('installed_modules', ['module_name' => 'BlockedPlugin']);
    }

    public function test_install_requires_purchase(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $plugin = $this->createPlugin(['slug' => 'unpurchased-plugin', 'module_name' => 'UnpurchasedPlugin']);
        Sanctum::actingAs($admin);

        $this->postJson("/api/plugins/{$plugin->id}/install")->assertForbidden();
        $this->assertDatabaseMissing('installed_modules', ['module_name' => 'UnpurchasedPlugin']);
    }

    /**
     * The actual cross-tenant regression this ADR exists to prevent: one
     * school installing a plugin must never affect another school's own
     * `installed_modules` row or its own access to the gated route.
     */
    public function test_installing_in_one_school_does_not_affect_another_school(): void
    {
        $this->enter($this->school);
        $admin = User::factory()->create(['role' => 'admin']);
        $plugin = $this->createPlugin();
        $admin->pluginPurchases()->create(['plugin_product_id' => $plugin->id, 'status' => 'completed', 'purchased_at' => now()]);
        Sanctum::actingAs($admin);
        $this->postJson("/api/plugins/{$plugin->id}/install")->assertOk();
        $this->assertDatabaseHas('installed_modules', ['module_name' => 'AttendanceInsights', 'status' => 'installed']);

        $beta = $this->openSchool('beta');
        // A fresh tenant database: the plugin was never purchased or installed here.
        $this->assertDatabaseMissing('installed_modules', ['module_name' => 'AttendanceInsights']);
        $betaAdmin = User::factory()->create(['role' => 'admin']);
        $betaPlugin = $this->createPlugin();
        Sanctum::actingAs($betaAdmin);
        $this->postJson("/api/plugins/{$betaPlugin->id}/install")->assertForbidden(); // not purchased in beta
        $this->assertDatabaseMissing('installed_modules', ['module_name' => 'AttendanceInsights']);

        $this->app['auth']->forgetGuards();
        $this->enter($this->school);
        $this->assertDatabaseHas('installed_modules', ['module_name' => 'AttendanceInsights', 'status' => 'installed']);
    }

    public function test_ensure_module_installed_gate_blocks_until_installed_then_allows(): void
    {
        Route::middleware(['api', 'school', 'auth:sanctum', EnsureModuleInstalled::class.':AttendanceInsights'])
            ->get('/api/_test/attendance-insights-report', fn () => response()->json(['ok' => true]));

        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/_test/attendance-insights-report')
            ->assertForbidden()
            ->assertJsonPath('message', 'هذه الإضافة غير مفعّلة لمركزك.');

        $plugin = $this->createPlugin();
        $admin->pluginPurchases()->create(['plugin_product_id' => $plugin->id, 'status' => 'completed', 'purchased_at' => now()]);
        $this->postJson("/api/plugins/{$plugin->id}/install")->assertOk();

        $this->getJson('/api/_test/attendance-insights-report')->assertOk()->assertJsonPath('ok', true);
    }
}
