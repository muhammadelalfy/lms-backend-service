<?php

declare(strict_types=1);

namespace Modules\Ai\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Modules\Ai\Events\DraftPublished;
use Modules\Ai\Models\DraftExam;
use Modules\Ai\Models\DraftQuestion;
use Modules\Ai\Models\QuestionRubric;
use Modules\Ai\Tests\Concerns\BuildsBlueprints;
use Modules\Ai\Tests\Concerns\BuildsMaterials;
use Modules\Ai\Tests\Concerns\FakesExamAi;
use Modules\Auth\Models\AuditLog;
use Modules\Auth\Models\User;
use Modules\Exams\Models\ExamDepartment;
use Modules\Exams\Models\ExamQuestion;
use Modules\Exams\Models\ExamTemplate;
use Modules\Students\Models\Student;
use Modules\Students\Models\StudentAccount;
use Tests\TestCase;

class PublishDraftTest extends TestCase
{
    use BuildsBlueprints, BuildsMaterials, FakesExamAi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableAi();
    }

    /** Every type the AI writes: 2 MCQ, 1 true/false, 1 short answer, 1 fill-in, 1 essay = 2 + 1 + 2 + 2 + 5 = 12 marks. */
    private function mixedDraft(User $teacher): DraftExam
    {
        return $this->generatedDraft($teacher, [
            'type_counts' => ['mcq' => 2, 'true_false' => 1, 'short_answer' => 1, 'fill_blank' => 1, 'essay' => 1],
            'marks_per_type' => ['mcq' => 1, 'true_false' => 1, 'short_answer' => 2, 'fill_blank' => 2, 'essay' => 5],
            'total_marks' => 12,
        ]);
    }

    private function approveAll(DraftExam $draft): void
    {
        DraftQuestion::query()->where('draft_id', $draft->id)->where('state', 'pending')->update(['state' => 'approved']);
    }

    public function test_publishing_needs_every_question_approved_and_the_marks_to_add_up(): void
    {
        $teacher = $this->teacher();
        $draft = $this->mixedDraft($teacher);
        $url = "/api/ai/drafts/{$draft->id}/publish";

        $this->postJson($url)->assertStatus(422)->assertJsonPath('code', 'not_all_approved')
            ->assertJsonPath('message', 'يجب اعتماد كل الأسئلة المتبقية قبل النشر.')
            ->assertJsonPath('blockers.0.code', 'not_all_approved');

        $this->approveAll($draft);
        $mcq = DraftQuestion::query()->where('draft_id', $draft->id)->where('type', 'mcq')->firstOrFail();
        $this->deleteJson("/api/ai/drafts/{$draft->id}/questions/{$mcq->id}")->assertOk()
            ->assertJsonPath('data.marks_sum', 11)->assertJsonPath('data.blockers.0.code', 'marks_mismatch');
        $this->postJson($url)->assertStatus(422)->assertJsonPath('code', 'marks_mismatch');
        $this->assertSame(0, ExamTemplate::count());

        // The teacher may accept another total explicitly.
        $this->postJson($url, ['override_total' => true])->assertCreated();
    }

    public function test_publish_creates_a_normal_exam_template_in_one_go_and_freezes_the_draft(): void
    {
        Event::fake([DraftPublished::class]);
        $teacher = $this->teacher();
        $draft = $this->mixedDraft($teacher);
        $this->approveAll($draft);
        $department = ExamDepartment::create(['name' => 'علوم', 'slug' => 'science']);

        $response = $this->postJson("/api/ai/drafts/{$draft->id}/publish", ['department_id' => $department->id], ['Idempotency-Key' => 'publish-key-01'])
            ->assertCreated()->assertJsonPath('data.draft_id', $draft->id)->assertJsonPath('data.template_status', 'draft')
            ->assertJsonPath('data.questions_count', 6)->assertJsonPath('data.total_marks', 12);
        $templateId = $response->json('data.template_id');

        $template = ExamTemplate::query()->with('questions')->findOrFail($templateId);
        $this->assertSame('امتحان العلوم', $template->title);
        $this->assertSame('draft', $template->status);
        $this->assertSame($department->id, $template->department_id);
        $this->assertSame($teacher->id, $template->created_by);
        $this->assertSame(45, $template->duration_minutes);
        $this->assertSame('الصف الثاني الإعدادي', $template->grade);
        $this->assertSame(['mcq', 'mcq', 'true_false', 'essay', 'essay', 'essay'], $template->questions->pluck('type')->all());
        $this->assertSame([1, 1, 1, 2, 2, 5], $template->questions->pluck('points')->all());
        $this->assertSame([0, 1, 2, 3, 4, 5], $template->questions->pluck('sort_order')->all());
        $first = $template->questions[0];
        $this->assertSame('<p>سؤال رقم 1 عن التمثيل الضوئي</p>', $first->prompt_html);
        $this->assertSame(['أ1', 'ب1', 'ج1', 'د1'], $first->options);
        $this->assertSame('ب1', $first->correct_answer);
        $this->assertSame(['صواب', 'خطأ'], $template->questions[2]->options);
        $this->assertSame('صواب', $template->questions[2]->correct_answer);
        $this->assertNull($template->questions[5]->options);
        $this->assertNull($template->questions[5]->correct_answer);

        // Rubrics and model answers live beside the runner's tables.
        $rubrics = QuestionRubric::query()->orderBy('question_id')->get();
        $this->assertSame($template->questions->slice(3)->pluck('id')->values()->all(), $rubrics->pluck('question_id')->all());
        $this->assertSame('ai_draft', $rubrics[2]->source);
        $this->assertSame('إجابة نموذجية 6', $rubrics[2]->model_answer);
        $this->assertSame('يذكر الفكرة', $rubrics[2]->rubric['criteria'][0]['text']);

        $draft->refresh();
        $this->assertSame('published', $draft->status->value);
        $this->assertSame($templateId, $draft->template_id);
        $this->assertSame($teacher->id, $draft->approved_by);
        $this->assertNotNull($draft->published_at);
        $log = AuditLog::query()->where('action', 'ai_exams.draft_published')->firstOrFail();
        $this->assertSame(['template_id' => $templateId, 'questions' => 6, 'status' => 'draft', 'total_marks' => 12], $log->details);
        $this->assertSame($draft->id, $log->subject_id);
        Event::assertDispatched(DraftPublished::class, fn ($e) => $e->templateId === $templateId && $e->draftId === $draft->id);

        // Frozen: no more review; publishing again (any key) returns the same template.
        $q = DraftQuestion::query()->where('draft_id', $draft->id)->firstOrFail();
        $this->putJson("/api/ai/drafts/{$draft->id}/questions/{$q->id}", ['prompt' => 'تعديل'])->assertStatus(409)->assertJsonPath('code', 'draft_published');
        $this->postJson("/api/ai/drafts/{$draft->id}/approve-all-unflagged")->assertStatus(409);
        $this->postJson("/api/ai/drafts/{$draft->id}/questions/{$q->id}/regenerate")->assertStatus(409)->assertJsonPath('code', 'draft_published');
        $this->postJson("/api/ai/drafts/{$draft->id}/publish", [], ['Idempotency-Key' => 'publish-key-01'])->assertCreated()->assertJsonPath('data.template_id', $templateId);
        $this->postJson("/api/ai/drafts/{$draft->id}/publish", [], ['Idempotency-Key' => 'publish-key-02'])->assertOk()->assertJsonPath('data.template_id', $templateId);
        $this->postJson("/api/ai/drafts/{$draft->id}/publish")->assertOk()->assertJsonPath('data.template_id', $templateId);
        $this->assertSame(1, ExamTemplate::count());
        $this->assertSame(6, ExamQuestion::count());
        $this->getJson("/api/ai/drafts/{$draft->id}")->assertJsonPath('data.frozen', true)->assertJsonPath('data.template_id', $templateId);

        // The manual builder takes over: the template is a normal one for the existing endpoints.
        $this->putJson("/api/exam-templates/{$templateId}", ['status' => 'published'])->assertOk()->assertJsonPath('status', 'published');
    }

    public function test_a_student_takes_the_published_exam_through_the_existing_runner_without_answer_keys(): void
    {
        $teacher = $this->teacher();
        $draft = $this->mixedDraft($teacher);
        $this->approveAll($draft);
        $templateId = $this->postJson("/api/ai/drafts/{$draft->id}/publish", ['status' => 'published'])->assertCreated()->json('data.template_id');

        $student = Student::factory()->create();
        $studentUser = User::factory()->create(['role' => 'student']);
        StudentAccount::create(['user_id' => $studentUser->id, 'student_id' => $student->id, 'relationship' => 'student']);
        Sanctum::actingAs($studentUser);

        $list = $this->getJson('/api/exam-templates')->assertOk()->assertJsonPath('data.0.id', $templateId)->assertJsonCount(6, 'data.0.questions');
        foreach ($list->json('data.0.questions') as $question) {
            $this->assertArrayNotHasKey('correct_answer', $question);
            $this->assertContains($question['type'], ['mcq', 'true_false', 'essay']);
            $this->assertArrayNotHasKey('rubric', $question);
            $this->assertArrayNotHasKey('explanation', $question);
        }
        $session = $this->postJson("/api/exam-templates/{$templateId}/start")->assertCreated();
        $this->assertArrayNotHasKey('correct_answer', $session->json('template.questions.0'));
        $questions = $session->json('template.questions');
        $this->postJson("/api/exam-sessions/{$session->json('id')}/answers/batch", ['answers' => [
            ['question_id' => $questions[0]['id'], 'answer' => 'ب1'],
            ['question_id' => $questions[5]['id'], 'answer' => 'تصنع النباتات غذاءها بالضوء'],
        ]])->assertOk()->assertJsonPath('saved', 2);
        $submitted = $this->postJson("/api/exam-sessions/{$session->json('id')}/submit")->assertOk()->assertJsonPath('status', 'submitted');
        $this->assertArrayNotHasKey('correct_answer', $submitted->json('template.questions.0'));

        Sanctum::actingAs($teacher);
        $this->assertSame('ب1', $this->getJson('/api/exam-templates')->json('data.0.questions.0.correct_answer'));
    }

    public function test_a_draft_published_as_draft_is_not_open_to_students_yet(): void
    {
        $teacher = $this->teacher();
        $draft = $this->mixedDraft($teacher);
        $this->approveAll($draft);
        $templateId = $this->postJson("/api/ai/drafts/{$draft->id}/publish")->assertCreated()->json('data.template_id');

        $student = Student::factory()->create();
        $studentUser = User::factory()->create(['role' => 'student']);
        StudentAccount::create(['user_id' => $studentUser->id, 'student_id' => $student->id, 'relationship' => 'student']);
        Sanctum::actingAs($studentUser);

        $this->getJson('/api/exam-templates')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/exam-templates/{$templateId}/start")->assertStatus(422);
    }

    public function test_a_draft_still_generating_cannot_be_published(): void
    {
        $teacher = $this->teacher();
        $draft = $this->generatedDraft($teacher);
        $this->approveAll($draft);
        Queue::fake();
        $q = DraftQuestion::query()->where('draft_id', $draft->id)->where('type', 'mcq')->firstOrFail();
        $this->postJson("/api/ai/drafts/{$draft->id}/questions/{$q->id}/regenerate")->assertStatus(202);

        $this->postJson("/api/ai/drafts/{$draft->id}/publish")->assertStatus(409)->assertJsonPath('code', 'generating');
        $this->assertSame(0, ExamTemplate::count());
    }

    public function test_an_admin_publishes_a_teachers_draft_and_the_department_must_exist(): void
    {
        $teacher = $this->teacher();
        $draft = $this->mixedDraft($teacher);
        $this->approveAll($draft);
        $admin = $this->actAs('admin');

        $this->postJson("/api/ai/drafts/{$draft->id}/publish", ['department_id' => 999])->assertStatus(422)->assertJsonValidationErrors(['department_id']);
        $this->postJson("/api/ai/drafts/{$draft->id}/publish", ['status' => 'archived'])->assertStatus(422)->assertJsonValidationErrors(['status']);
        $templateId = $this->postJson("/api/ai/drafts/{$draft->id}/publish")->assertCreated()->json('data.template_id');
        $this->assertSame($admin->id, ExamTemplate::findOrFail($templateId)->created_by);
    }
}
