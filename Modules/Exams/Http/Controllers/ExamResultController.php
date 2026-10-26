<?php

namespace Modules\Exams\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Exams\Actions\ChangeDraftExamResult;
use Modules\Exams\Actions\CorrectExamResult;
use Modules\Exams\Actions\PublishExamResult;
use Modules\Exams\Actions\RecordExamResult;
use Modules\Exams\DTOs\CorrectionData;
use Modules\Exams\DTOs\ExamResultData;
use Modules\Exams\Enums\ExamResultStatus;
use Modules\Exams\Http\Requests\CorrectExamResultRequest;
use Modules\Exams\Http\Requests\DeleteExamResultRequest;
use Modules\Exams\Http\Requests\ListCorrectionsRequest;
use Modules\Exams\Http\Requests\PublishExamResultRequest;
use Modules\Exams\Http\Requests\StoreExamResultRequest;
use Modules\Exams\Http\Requests\UpdateExamResultRequest;
use Modules\Exams\Models\ExamResult;
use Modules\Exams\Models\ExamResultCorrection;
use Modules\Groups\Models\ClassGroup;

/**
 * Exam results (S6): draft -> published -> corrected. Drafts are edited and deleted freely by their
 * recorder; a published result is final for everyone and changes only through the admin's audited
 * correction. Students and parents never see drafts. Responses keep the shape the apps already read
 * (the model with its student), with `status`, `published_at` and `last_corrected_at` added.
 */
class ExamResultController extends Controller
{
    public function index(Request $request)
    {
        $query = ExamResult::with('student')->latest('taken_at');
        $this->scope($query, $request);
        if ($request->filled('status') && ! $request->user()->isAnyRole('student', 'parent')) {
            $request->validate(['status' => [Rule::in(ExamResultStatus::values())]]);
            $query->where('status', $request->string('status')->toString());
        }

        return $query->paginate(50);
    }

    public function store(StoreExamResultRequest $request, RecordExamResult $record)
    {
        $result = $record->handle($request->user(), ExamResultData::fromValidated($request->validated()));

        return response()->json($result->load('student'), 201);
    }

    public function update(UpdateExamResultRequest $request, ExamResult $exam, ChangeDraftExamResult $change)
    {
        return $change->update($exam, ExamResultData::fromValidated($request->validated()))->load('student');
    }

    public function destroy(DeleteExamResultRequest $request, ExamResult $exam, ChangeDraftExamResult $change)
    {
        $change->delete($exam);

        return response()->noContent();
    }

    public function publish(PublishExamResultRequest $request, ExamResult $exam, PublishExamResult $publish)
    {
        return $publish->handle($request->user(), $exam)->load('student');
    }

    public function correct(CorrectExamResultRequest $request, ExamResult $exam, CorrectExamResult $correct)
    {
        ['result' => $result, 'group' => $group] = $correct->handle($request->user(), $exam, CorrectionData::fromValidated($request->validated()));

        return response()->json([...$result->load('student')->toArray(), 'correction_group_id' => $group]);
    }

    /** The audit trail of a result, most recent first (admin only). */
    public function corrections(ListCorrectionsRequest $request, ExamResult $exam)
    {
        return ExamResultCorrection::query()->with('corrector:id,name')->where('exam_result_id', $exam->id)
            ->orderByDesc('id')->paginate(50)
            ->through(fn (ExamResultCorrection $c) => [
                'id' => $c->id,
                'correction_group_id' => $c->correction_group_id,
                'field' => $c->field,
                'old_value' => $c->old_value,
                'new_value' => $c->new_value,
                'reason' => $c->reason,
                'corrected_by' => $c->corrector ? ['id' => $c->corrector->id, 'name' => $c->corrector->name] : null,
                'corrected_at' => $c->corrected_at?->toIso8601String(),
            ]);
    }

    private function scope($query, Request $request): void
    {
        $user = $request->user();
        $account = $user->studentAccount;
        if ($user->isAnyRole('student', 'parent')) {
            abort_unless($account, 403);
            $query->where('student_id', $account->student_id)->visibleToStudents();

            return;
        }
        if ($user->isAnyRole('teacher')) {
            $excluded = ClassGroup::namesClaimedByOthers($user);
            $query->whereHas('student', fn ($q) => $q->whereNotIn('group', $excluded));
        }
    }
}
