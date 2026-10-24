<?php

namespace Modules\PluginStore\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\PluginStore\Models\InstalledModule;
use Modules\PluginStore\Models\PluginProduct;
use Modules\PluginStore\Services\PluginInstaller;

class PluginStoreController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $purchased = $user->pluginPurchases()->where('status', 'completed')->pluck('plugin_product_id');
        $installed = InstalledModule::query()->where('status', 'installed')->pluck('module_name', 'plugin_product_id');

        $plugins = PluginProduct::query()->where('is_active', true)->orderBy('name')->get()->map(function (PluginProduct $plugin) use ($purchased, $installed): array {
            return [
                'id' => $plugin->id,
                'slug' => $plugin->slug,
                'name' => $plugin->name,
                'description' => $plugin->description,
                'version' => $plugin->version,
                'module_name' => $plugin->module_name,
                'price' => $plugin->price,
                'purchased' => $purchased->contains($plugin->id),
                'installed' => $installed->has($plugin->id),
                'installed_module' => $installed->get($plugin->id),
                'metadata' => $plugin->metadata,
            ];
        });

        return response()->json(['data' => $plugins]);
    }

    public function installed(): JsonResponse
    {
        return response()->json(['data' => InstalledModule::with('plugin')->latest('installed_at')->get()]);
    }

    public function purchase(Request $request, PluginProduct $plugin): JsonResponse
    {
        abort_unless($plugin->is_active, 404);
        $purchase = $request->user()->pluginPurchases()->firstOrCreate(
            ['plugin_product_id' => $plugin->id],
            ['status' => 'completed', 'purchased_at' => now()],
        );

        return response()->json(['purchase' => $purchase->load('plugin')], $purchase->wasRecentlyCreated ? 201 : 200);
    }

    public function install(Request $request, PluginProduct $plugin, PluginInstaller $installer): JsonResponse
    {
        abort_unless($request->user()->isAnyRole('admin'), 403, 'تثبيت الإضافات متاح لمدير النظام فقط.');
        abort_unless($plugin->is_active, 404);
        abort_unless($request->user()->pluginPurchases()->where('plugin_product_id', $plugin->id)->where('status', 'completed')->exists(), 403, 'يجب شراء الإضافة قبل تثبيتها.');

        $module = $installer->install($plugin, $request->user()->id);

        return response()->json(['module' => $module->load('plugin'), 'message' => 'تم تفعيل الإضافة لمركزك.']);
    }

    public function uninstall(Request $request, PluginProduct $plugin, PluginInstaller $installer): JsonResponse
    {
        abort_unless($request->user()->isAnyRole('admin'), 403, 'إدارة الإضافات متاحة لمدير النظام فقط.');
        $module = InstalledModule::query()->where('plugin_product_id', $plugin->id)->firstOrFail();

        $installer->uninstall($module);

        return response()->json(['message' => 'تم إلغاء تفعيل الإضافة لمركزك.']);
    }
}
