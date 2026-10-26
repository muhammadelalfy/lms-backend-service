<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Ai\Actions\AddDraftQuestion;
use Modules\Ai\Actions\ApproveUnflaggedQuestions;
use Modules\Ai\Actions\EditDraftQuestion;
use Modules\Ai\Actions\PublishDraft;
use Modules\Ai\Actions\RegenerateDraftQuestion;
use Modules\Ai\Actions\ReorderDraftQuestions;
use Modules\Ai\Actions\ReviewDraftQuestion;
use Modules\Ai\DTOs\DraftQuestionData;
use Modules\Ai\Http\Requests\AddDraftQuestionRequest;
use Modules\Ai\Http\Requests\DraftRequest;
use Modules\Ai\Http\Requests\PublishDraftRequest;
use Modules\Ai\Http\Requests\RegenerateQuestionRequest;
use Modules\Ai\Http\Requests\ReorderDraftRequest;
use Modules\Ai\Http\Requests\ReviewDraftRequest;
use Modules\Ai\Http\Requests\UpdateDraftQuestionRequest;
use Modules\Ai\Http\Resources\DraftResource;
use Modules\Ai\Http\Resources\GenerationJobResource;
use Modules\Ai\Models\DraftEvent;
use Modules\Ai\Models\DraftExam;
use Modules\Ai\Models\DraftQuestion;

/**
 * The teacher's review of a draft: edit, add, remove, approve, reorder, regenerate one question, then
 * publish into Exams. Every review action answers with the whole draft (counts and blockers included).
 */
class DraftReviewController extends Controller
{
    public function update(UpdateDraftQuestionRequest $request, DraftExam $draft, DraftQuestion $question, EditDraftQuestion $edit)
    {
        $edit->handle($request->user(), $question, DraftQuestionData::fromValidated($request->validated()));

        return $this->draft($draft);
    }

    public function store(AddDraftQuestionRequest $request, DraftExam $draft, AddDraftQuestion $add)
    {
        $add->handle($request->user(), $draft, DraftQuestionData::fromValidated($request->validated()));

        return $this->draft($draft)->response()->setStatusCode(201);
    }

    public function destroy(ReviewDraftRequest $request, DraftExam $draft, DraftQuestion $question, ReviewDraftQuestion $review)
    {
        $review->remove($request->user(), $question);

        return $this->draft($draft);
    }

    public function approve(ReviewDraftRequest $request, DraftExam $draft, DraftQuestion $question, ReviewDraftQuestion $review)
    {
        $review->approve($request->user(), $question);

        return $this->draft($draft);
    }

    public function unapprove(ReviewDraftRequest $request, DraftExam $draft, DraftQuestion $question, ReviewDraftQuestion $review)
    {
        $review->unapprove($request->user(), $question);

        return $this->draft($draft);
    }

    public function approveUnflagged(ReviewDraftRequest $request, DraftExam $draft, ApproveUnflaggedQuestions $approve)
    {
        $count = $approve->handle($request->user(), $draft);

        return $this->draft($draft)->additional(['approved_count' => $count]);
    }

    public function reorder(ReorderDraftRequest $request, DraftExam $draft, ReorderDraftQuestions $reorder)
    {
        $reorder->handle($request->user(), $draft, $request->validated('question_ids'));

        return $this->draft($draft);
    }

    public function regenerate(RegenerateQuestionRequest $request, DraftExam $draft, DraftQuestion $question, RegenerateDraftQuestion $regenerate)
    {
        $job = $regenerate->handle($request->user(), $draft, $question, $request->validated('instruction'), $request->idempotencyKey());

        return (new GenerationJobResource($job->refresh()))->response()->setStatusCode(202);
    }

    public function publish(PublishDraftRequest $request, DraftExam $draft, PublishDraft $publish)
    {
        ['template' => $template, 'created' => $created] = $publish->handle($request->user(), $draft, $request->validated());

        return response()->json(['data' => [
            'draft_id' => $draft->id,
            'template_id' => $template->id,
            'template_status' => $template->status,
            'questions_count' => $template->questions()->count(),
            'total_marks' => (int) $template->questions()->sum('points'),
        ]], $created ? 201 : 200);
    }

    /** The review history: who decided what, newest first. */
    public function events(DraftRequest $request, DraftExam $draft)
    {
        return DraftEvent::query()->with('actor:id,name')->where('draft_id', $draft->id)->orderByDesc('id')->cursorPaginate(50)
            ->through(fn (DraftEvent $e) => [
                'id' => $e->id, 'action' => $e->action, 'question_id' => $e->question_id, 'details' => $e->details,
                'actor' => $e->actor ? ['id' => $e->actor->id, 'name' => $e->actor->name] : null,
                'created_at' => $e->created_at?->toIso8601String(),
            ]);
    }

    private function draft(DraftExam $draft): DraftResource
    {
        return new DraftResource(DraftController::loaded($draft->refresh()));
    }
}
