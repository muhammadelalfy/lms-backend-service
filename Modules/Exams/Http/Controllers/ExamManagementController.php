<?php

namespace Modules\Exams\Http\Controllers;

use Modules\Core\Http\Concerns\AuthorizesStaff;
use App\Http\Controllers\Controller;
use Modules\Exams\Models\ExamDepartment;
use Modules\Exams\Models\ExamQuestion;
use Modules\Exams\Models\ExamSession;
use Modules\Exams\Models\ExamSessionAnswer;
use Modules\Exams\Models\ExamSessionEvent;
use Modules\Exams\Models\ExamTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Exams\Events\ExamSessionSubmitted;
use Modules\Exams\Services\ExamPaperPdfService;

class ExamManagementController extends Controller
{
    use AuthorizesStaff;

    public function departments(Request $request)
    {
        $this->authorizeStaff($request);
        return ExamDepartment::query()->where('is_active', true)->orderBy('name')->get();
    }

    public function storeDepartment(Request $request)
    {
        $this->authorizeStaff($request);
        $data = $request->validate(['name' => 'required|string|max:255', 'slug' => 'required|string|max:255|alpha_dash|unique:exam_departments,slug', 'description' => 'nullable|string']);
        return response()->json(ExamDepartment::create($data), 201);
    }

    public function updateDepartment(Request $request, ExamDepartment $department)
    {
        $this->authorizeStaff($request);
        $data = $request->validate(['name' => 'sometimes|string|max:255', 'slug' => 'sometimes|string|max:255|alpha_dash|unique:exam_departments,slug,' . $department->id, 'description' => 'nullable|string', 'is_active' => 'sometimes|boolean']);
        $department->update($data);
        return $department->fresh();
    }

    public function destroyDepartment(Request $request, ExamDepartment $department)
    {
        $this->authorizeStaff($request);
        if ($department->templates()->exists()) {
            return response()->json(['message' => 'لا يمكن حذف قسم مرتبط بقوالب امتحانات. عطّل القسم بدلاً من ذلك.'], 422);
        }
        $department->delete();
        return response()->noContent();
    }

    public function templates(Request $request)
    {
        if ($request->user()->isAnyRole('student', 'parent')) {
            return ExamTemplate::with(['department', 'questions'])->where('status', 'published')->latest()->paginate(50)
                ->through(fn (ExamTemplate $template) => $this->withoutAnswerKeys($template));
        }
        $this->authorizeStaff($request);
        return ExamTemplate::with(['department', 'questions'])->latest()->paginate(50);
    }

    public function storeTemplate(Request $request)
    {
        $this->authorizeStaff($request);
        $data = $request->validate([
            'department_id' => 'nullable|exists:exam_departments,id', 'title' => 'required|string|max:255', 'grade' => 'nullable|string|max:255',
            'duration_minutes' => 'required|integer|min:1|max:600', 'instructions' => 'nullable|string', 'watermark_text' => 'nullable|string|max:255',
            'watermark_opacity' => 'nullable|integer|min:0|max:50', 'status' => 'nullable|in:draft,published,archived',
            'questions' => 'array', 'questions.*.type' => 'required|in:mcq,true_false,essay,math,geometry', 'questions.*.prompt_html' => 'required|string',
            'questions.*.options' => 'nullable|array', 'questions.*.correct_answer' => 'nullable|string', 'questions.*.points' => 'required|integer|min:1|max:100',
        ]);
        return DB::transaction(function () use ($data, $request) {
            $questions = $data['questions'] ?? [];
            unset($data['questions']);
            $template = ExamTemplate::create([...$data, 'created_by' => $request->user()->id]);
            foreach ($questions as $index => $question) $template->questions()->create([...$question, 'sort_order' => $index]);
            return response()->json($template->load(['department', 'questions']), 201);
        });
    }

    public function updateTemplate(Request $request, ExamTemplate $template)
    {
        $this->authorizeStaff($request);
        $data = $request->validate([
            'department_id' => 'nullable|exists:exam_departments,id', 'title' => 'sometimes|string|max:255', 'grade' => 'nullable|string|max:255',
            'duration_minutes' => 'sometimes|integer|min:1|max:600', 'instructions' => 'nullable|string', 'watermark_text' => 'nullable|string|max:255',
            'watermark_opacity' => 'nullable|integer|min:0|max:50', 'status' => 'sometimes|in:draft,published,archived',
            'questions' => 'sometimes|array', 'questions.*.id' => 'nullable|integer', 'questions.*.type' => 'required_with:questions|in:mcq,true_false,essay,math,geometry',
            'questions.*.prompt_html' => 'required_with:questions|string', 'questions.*.options' => 'nullable|array', 'questions.*.correct_answer' => 'nullable|string',
            'questions.*.points' => 'required_with:questions|integer|min:1|max:100', 'questions.*.sort_order' => 'nullable|integer|min:0',
        ]);

        return DB::transaction(function () use ($data, $template) {
            $questions = $data['questions'] ?? null;
            unset($data['questions']);
            $template->update($data);

            if ($questions !== null) {
                $existingIds = $template->questions()->pluck('id')->all();
                $incomingIds = [];
                foreach ($questions as $index => $question) {
                    $questionId = $question['id'] ?? null;
                    if ($questionId !== null) {
                        abort_unless(in_array($questionId, $existingIds, true), 422, 'السؤال لا ينتمي إلى هذا القالب.');
                        $incomingIds[] = $questionId;
                        $template->questions()->whereKey($questionId)->update([...$question, 'sort_order' => $index]);
                    } else {
                        $createdQuestion = $template->questions()->create([...$question, 'sort_order' => $index]);
                        $incomingIds[] = $createdQuestion->id;
                    }
                }
                $template->questions()->whereNotIn('id', $incomingIds)->delete();
            }

            return $template->fresh(['department', 'questions']);
        });
    }

    public function destroyTemplate(Request $request, ExamTemplate $template)
    {
        $this->authorizeStaff($request);
        $template->delete();
        return response()->noContent();
    }

    public function downloadPdf(Request $request, ExamTemplate $template, ExamPaperPdfService $pdfService)
    {
        if ($request->user()->isAnyRole('student', 'parent')) {
            abort_unless($template->status === 'published', 404);
        } else {
            $this->authorizeStaff($request);
        }

        $filename = str($template->title)->slug('-')->append('.pdf')->toString() ?: 'exam-paper.pdf';
        return response($pdfService->render($template), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function startSession(Request $request, ExamTemplate $template)
    {
        $user = $request->user();
        abort_unless($user->isAnyRole('student', 'parent'), 403);
        $account = $user->studentAccount;
        abort_unless($account, 403);
        abort_unless($template->status === 'published', 422, 'الامتحان غير منشور.');
        $session = ExamSession::firstOrCreate(['template_id' => $template->id, 'student_id' => $account->student_id], ['camera_required' => true, 'fullscreen_required' => true]);
        $session->load(['template.questions', 'answers']);
        $this->withoutAnswerKeys($session->template);

        return $session;
    }

    public function event(Request $request, ExamSession $session)
    {
        $this->authorizeSessionOwner($request, $session);
        $data = $request->validate(['type' => 'required|in:camera_granted,camera_denied,fullscreen_entered,fullscreen_exited,focus_lost,focus_restored,visibility_hidden,visibility_visible,submitted,heartbeat', 'metadata' => 'nullable|array']);
        return DB::transaction(function () use ($session, $data) {
            $session->update(['last_event_at' => now(), 'focus_loss_count' => $data['type'] === 'focus_lost' || $data['type'] === 'visibility_hidden' ? $session->focus_loss_count + 1 : $session->focus_loss_count, 'status' => in_array($data['type'], ['focus_lost', 'visibility_hidden']) ? 'flagged' : ($session->status === 'ready' ? 'active' : $session->status), 'started_at' => $session->started_at ?: now()]);
            return $session->events()->create([...$data, 'occurred_at' => now()]);
        });
    }

    public function answer(Request $request, ExamSession $session)
    {
        $this->authorizeSessionOwner($request, $session);
        abort_if(in_array($session->status, ['submitted', 'expired']), 422, 'لا يمكن تعديل إجابة هذا الامتحان.');
        $data = $request->validate(['question_id' => 'required|exists:exam_questions,id', 'answer' => 'nullable|string']);
        abort_unless($session->template->questions()->whereKey($data['question_id'])->exists(), 422);
        $answer = ExamSessionAnswer::updateOrCreate(['session_id' => $session->id, 'question_id' => $data['question_id']], ['answer' => $data['answer'] ?? null, 'answered_at' => now()]);
        $session->update(['status' => $session->status === 'ready' ? 'active' : $session->status, 'started_at' => $session->started_at ?: now()]);
        return $answer;
    }

    /**
     * Saves several answers in one request and one write: a student's phone
     * sends everything changed since its last save, so thousands of students
     * in the same exam cost thousands of small requests, not tens of
     * thousands. Saving the same answers twice changes nothing.
     */
    public function answers(Request $request, ExamSession $session)
    {
        $this->authorizeSessionOwner($request, $session);
        abort_if(in_array($session->status, ['submitted', 'expired']), 422, 'لا يمكن تعديل إجابة هذا الامتحان.');
        $data = $request->validate([
            'answers' => 'required|array|min:1|max:100',
            'answers.*.question_id' => 'required|integer',
            'answers.*.answer' => 'nullable|string',
        ]);

        // The last value sent for a question wins.
        $latest = collect($data['answers'])->keyBy('question_id');
        $allowed = $session->template->questions()->whereIn('id', $latest->keys())->pluck('id')->all();
        abort_unless(count($allowed) === $latest->count(), 422, 'سؤال غير موجود في هذا الامتحان.');

        $now = now();
        ExamSessionAnswer::upsert(
            $latest->map(fn (array $row) => [
                'session_id' => $session->id, 'question_id' => (int) $row['question_id'],
                'answer' => $row['answer'] ?? null, 'answered_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ])->values()->all(),
            ['session_id', 'question_id'],
            ['answer', 'answered_at', 'updated_at'],
        );
        $session->update(['status' => $session->status === 'ready' ? 'active' : $session->status, 'started_at' => $session->started_at ?: $now]);

        return ['saved' => $latest->count()];
    }

    public function submit(Request $request, ExamSession $session)
    {
        $this->authorizeSessionOwner($request, $session);
        abort_if(in_array($session->status, ['submitted', 'expired']), 422, 'تم إنهاء هذا الامتحان بالفعل.');
        // Submission is one state transition (§11): locked so a repeated or
        // concurrent submit cannot double-submit, and atomic so the "submitted"
        // status and its audit event never land separately.
        DB::transaction(function () use ($session): void {
            $locked = ExamSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            abort_if(in_array($locked->status, ['submitted', 'expired']), 422, 'تم إنهاء هذا الامتحان بالفعل.');
            $locked->update(['status' => 'submitted', 'submitted_at' => now(), 'last_event_at' => now()]);
            $locked->events()->create(['type' => 'submitted', 'occurred_at' => now()]);
            // After commit: grading assistance (Modules/Ai) prepares the written answers' review.
            DB::afterCommit(fn () => event(new ExamSessionSubmitted($locked->id)));
        });
        $session = $session->fresh(['template.questions', 'answers', 'events']);
        $this->withoutAnswerKeys($session->template);

        return $session;
    }

    private function withoutAnswerKeys(ExamTemplate $template): ExamTemplate
    {
        $template->questions->each->makeHidden('correct_answer');

        return $template;
    }

    private function authorizeSessionOwner(Request $request, ExamSession $session): void
    {
        $account = $request->user()->studentAccount;
        abort_unless($account && $account->student_id === $session->student_id, 403);
    }
}
