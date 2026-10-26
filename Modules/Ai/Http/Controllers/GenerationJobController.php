<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Ai\Actions\CancelGeneration;
use Modules\Ai\Actions\EstimateGeneration;
use Modules\Ai\Actions\RetryGeneration;
use Modules\Ai\Actions\StartGeneration;
use Modules\Ai\Http\Requests\EstimateGenerationRequest;
use Modules\Ai\Http\Requests\GenerationJobRequest;
use Modules\Ai\Http\Requests\StartGenerationRequest;
use Modules\Ai\Http\Resources\GenerationJobResource;
use Modules\Ai\Models\GenerationJob;

/** Estimate, start, follow, cancel and retry AI generation. The work itself runs on the `ai` queue. */
class GenerationJobController extends Controller
{
    public function estimate(EstimateGenerationRequest $request, EstimateGeneration $estimate)
    {
        return response()->json(['data' => $estimate->handle($request->user(), $request->snapshot())]);
    }

    public function store(StartGenerationRequest $request, StartGeneration $start)
    {
        $job = $start->handle($request->user(), $request->blueprint(), $request->idempotencyKey());

        return (new GenerationJobResource($job->refresh()))->response()->setStatusCode(202);
    }

    public function show(GenerationJobRequest $request, GenerationJob $job)
    {
        return new GenerationJobResource($job);
    }

    public function cancel(GenerationJobRequest $request, GenerationJob $job, CancelGeneration $cancel)
    {
        if (! $cancel->handle($job)) {
            return response()->json(['message' => 'انتهى هذا التوليد بالفعل.', 'code' => 'not_cancellable'], 409);
        }

        return new GenerationJobResource($job->refresh());
    }

    public function retry(GenerationJobRequest $request, GenerationJob $job, RetryGeneration $retry)
    {
        return (new GenerationJobResource($retry->handle($job)))->response()->setStatusCode(202);
    }
}
