<?php

declare(strict_types=1);

namespace Modules\Tenancy\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Tenancy\Services\PlanCatalog;

/** The public plan catalog: for the landing page, the web app and anyone comparing plans. */
class PlansController
{
    public function __invoke(PlanCatalog $catalog): JsonResponse
    {
        return response()->json($catalog->cached())
            ->header('Cache-Control', 'public, max-age=300');
    }
}
