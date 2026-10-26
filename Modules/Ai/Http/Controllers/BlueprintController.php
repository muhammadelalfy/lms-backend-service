<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Ai\Actions\DuplicateBlueprint;
use Modules\Ai\Actions\SaveBlueprint;
use Modules\Ai\DTOs\BlueprintData;
use Modules\Ai\Http\Requests\BlueprintRequest;
use Modules\Ai\Http\Requests\DeleteBlueprintRequest;
use Modules\Ai\Http\Requests\DuplicateBlueprintRequest;
use Modules\Ai\Http\Requests\ListBlueprintsRequest;
use Modules\Ai\Http\Requests\StoreBlueprintRequest;
use Modules\Ai\Http\Requests\UpdateBlueprintRequest;
use Modules\Ai\Http\Resources\BlueprintResource;
use Modules\Ai\Models\ExamBlueprint;

/** Exam standards (blueprints) and the center's reusable standards templates. */
class BlueprintController extends Controller
{
    /** `?templates=1`: the templates the user may use (own + shared); otherwise their own blueprints (all, for the admin). */
    public function index(ListBlueprintsRequest $request)
    {
        $user = $request->user();
        $query = ExamBlueprint::query()->with('owner:id,name')->orderByDesc('id');
        $request->boolean('templates')
            ? $query->visibleTemplatesFor($user)
            : $query->where('is_template', false)->when(! $user->isAnyRole('admin'), fn ($q) => $q->where('owner_user_id', $user->id));

        return BlueprintResource::collection($query->cursorPaginate(25));
    }

    public function store(StoreBlueprintRequest $request, SaveBlueprint $save)
    {
        $blueprint = $save->create($request->user(), BlueprintData::fromValidated($request->validated()));

        return (new BlueprintResource($blueprint->load('owner:id,name')))->response()->setStatusCode(201);
    }

    public function show(BlueprintRequest $request, ExamBlueprint $blueprint)
    {
        return new BlueprintResource($blueprint->load('owner:id,name'));
    }

    public function update(UpdateBlueprintRequest $request, ExamBlueprint $blueprint, SaveBlueprint $save)
    {
        $save->update($blueprint, BlueprintData::fromValidated($request->validated()));

        return new BlueprintResource($blueprint->load('owner:id,name'));
    }

    public function destroy(DeleteBlueprintRequest $request, ExamBlueprint $blueprint)
    {
        $blueprint->delete();

        return response()->noContent();
    }

    public function duplicate(DuplicateBlueprintRequest $request, ExamBlueprint $blueprint, DuplicateBlueprint $duplicate)
    {
        $copy = $duplicate->handle($request->user(), $blueprint, $request->validated());

        return (new BlueprintResource($copy->load('owner:id,name')))->response()->setStatusCode(201);
    }
}
