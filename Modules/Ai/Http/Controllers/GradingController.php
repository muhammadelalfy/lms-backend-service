<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Ai\Actions\BulkAcceptGrades;
use Modules\Ai\Actions\DecideGrade;
use Modules\Ai\DTOs\GradeDecisionData;
use Modules\Ai\Http\Requests\BulkAcceptRequest;
use Modules\Ai\Http\Requests\DecideGradeRequest;
use Modules\Ai\Http\Requests\GradingQueueRequest;
use Modules\Ai\Http\Resources\GradingItemResource;
use Modules\Ai\Models\GradingSuggestion;
use Modules\Exams\Models\ExamResult;
use Modules\Exams\Models\ExamSession;

/**
 * The teacher's grading queue for written answers (S71): list sorted by lowest AI confidence first
 * (items with no suggestion before them, blank answers last), decide one, accept many. The AI only
 * suggests; nothing is final until a teacher decides.
 */
class GradingController extends Controller
{
    private const WITH = ['session.student', 'template:id,title', 'question', 'answer', 'rubric', 'grade.decider:id,name'];

    public function queue(GradingQueueRequest $request)
    {
        $query = GradingSuggestion::query()->visibleTo($request->user())->with(self::WITH)
            ->when($request->filled('exam'), fn ($q) => $q->where('template_id', $request->integer('exam')))
            ->when($request->filled('question'), fn ($q) => $q->where('question_id', $request->integer('question')))
            ->when($request->filled('session'), fn ($q) => $q->where('session_id', $request->integer('session')))
            ->when($request->filled('class'), fn ($q) => $q->whereHas('session.student', fn ($s) => $s->where('group', $request->string('class')->toString())))
            ->when($request->filled('min_confidence'), fn ($q) => $q->where('confidence', '>=', (float) $request->input('min_confidence')))
            ->when($request->filled('needs_attention'), fn ($q) => $q->where('needs_attention', $request->boolean('needs_attention')));

        match ($request->input('status', 'pending')) {
            'pending' => $query->whereDoesntHave('grade'),
            'decided' => $query->whereHas('grade'),
            default => null,
        };
        if ($request->input('sort', 'confidence') === 'newest') {
            $query->orderByDesc('id');
        } else {
            $query->orderByRaw("case when status = 'empty' then 1 else 0 end")->orderByRaw('coalesce(confidence, -1)')->orderBy('id');
        }

        return GradingItemResource::collection($query->paginate(50));
    }

    public function decide(DecideGradeRequest $request, GradingSuggestion $suggestion, DecideGrade $decide)
    {
        $decide->handle($request->user(), $suggestion, GradeDecisionData::fromValidated($request->validated()));
        $item = GradingSuggestion::query()->with(self::WITH)->findOrFail($suggestion->id);
        $session = ExamSession::query()->find($suggestion->session_id);

        return (new GradingItemResource($item))->additional(['session' => [
            'id' => $suggestion->session_id,
            'grading_state' => $session?->grading_state,
            'exam_result_id' => ExamResult::query()->where('session_id', $suggestion->session_id)->value('id'),
        ]]);
    }

    public function bulkAccept(BulkAcceptRequest $request, BulkAcceptGrades $accept)
    {
        $ids = $request->validated('ids');

        return response()->json(['data' => $accept->handle(
            $request->user(),
            $ids === null ? null : array_map('intval', $ids),
            $ids === null ? $request->minConfidence() : null,
            $ids === null ? (int) $request->validated('exam_id') : null,
            $ids === null ? (int) $request->validated('count') : null,
        )]);
    }
}
