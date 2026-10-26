<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Ai\Enums\JobStatus;
use Modules\Ai\Enums\QuestionState;
use Modules\Ai\Http\Requests\DraftRequest;
use Modules\Ai\Http\Requests\ListDraftsRequest;
use Modules\Ai\Http\Resources\DraftResource;
use Modules\Ai\Models\DraftExam;

/** The drafts the AI wrote: list and review. */
class DraftController extends Controller
{
    public function index(ListDraftsRequest $request)
    {
        $user = $request->user();

        return DraftResource::collection(
            DraftExam::query()->with(['owner:id,name', 'jobs' => fn ($q) => $q->whereIn('status', JobStatus::activeValues())->latest('id')])
                ->when(! $user->isAnyRole('admin'), fn ($q) => $q->where('owner_user_id', $user->id))
                ->when($request->validated('status'), fn ($q, $status) => $q->where('status', $status))
                ->orderByDesc('id')
                ->cursorPaginate(25)
        );
    }

    /** `?history=1` adds removed and replaced questions; `?flagged_first=1` sorts the flagged ones first. */
    public function show(DraftRequest $request, DraftExam $draft)
    {
        return new DraftResource(self::loaded($draft, $request->boolean('history')));
    }

    public static function loaded(DraftExam $draft, bool $history = false): DraftExam
    {
        return $draft->load([
            'owner:id,name',
            'questions' => fn ($q) => $history ? $q : $q->whereIn('state', QuestionState::liveValues()),
            'jobs' => fn ($q) => $q->whereIn('status', JobStatus::activeValues())->latest('id'),
        ]);
    }
}
