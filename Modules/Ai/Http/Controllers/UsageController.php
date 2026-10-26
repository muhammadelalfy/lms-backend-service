<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Ai\Http\Requests\UsageRequest;
use Modules\Ai\Services\UsageReport;

/** AI units used this month against the plan's cap, by feature, by exam and per day. */
class UsageController extends Controller
{
    public function show(UsageRequest $request, UsageReport $report)
    {
        return response()->json(['data' => $report->for($request->user())]);
    }
}
