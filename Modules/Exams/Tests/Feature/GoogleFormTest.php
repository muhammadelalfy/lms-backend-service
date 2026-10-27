<?php

namespace Modules\Exams\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Auth\Models\User;
use Modules\Calls\Models\ProviderAccount;
use Modules\Exams\Models\ExamQuestion;
use Modules\Exams\Models\ExamTemplate;
use Modules\Students\Models\Student;
use Tests\TestCase;

class GoogleFormTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;
    private ExamTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'g', 'services.google.client_secret' => 's']);
        $this->teacher = User::factory()->create(['role' => 'teacher']);
        ProviderAccount::create(['user_id' => $this->teacher->id, 'provider' => 'google', 'access_token' => 'tok', 'refresh_token' => 'r', 'expires_at' => now()->addHour()]);
        $this->template = ExamTemplate::create(['created_by' => $this->teacher->id, 'title' => 'امتحان الجبر', 'duration_minutes' => 30, 'status' => 'published']);
        ExamQuestion::create(['template_id' => $this->template->id, 'type' => 'mcq', 'prompt_html' => '<p>٢+٢؟</p>', 'options' => ['3', '4'], 'correct_answer' => '4', 'points' => 2, 'sort_order' => 1]);
        ExamQuestion::create(['template_id' => $this->template->id, 'type' => 'true_false', 'prompt_html' => '<p>الأرض مسطحة</p>', 'options' => null, 'correct_answer' => 'false', 'points' => 1, 'sort_order' => 2]);
    }

    private function fakeGoogle(): void
    {
        $item = fn ($id) => ['questionItem' => ['question' => ['questionId' => $id]]];
        Http::fake([
            'forms.googleapis.com/v1/forms/F1:batchUpdate' => Http::response(['form' => ['items' => [$item('n'), $item('e'), $item('q1'), $item('q2')]]]),
            'forms.googleapis.com/v1/forms/F1/responses*' => Http::response(['responses' => [[
                'responseId' => 'r1', 'lastSubmittedTime' => '2026-10-28T10:00:00Z', 'totalScore' => 2,
                'answers' => [
                    'n' => ['textAnswers' => ['answers' => [['value' => 'منى علي']]]],
                    'e' => ['textAnswers' => ['answers' => [['value' => 'Mona@Example.com']]]],
                    'q1' => ['textAnswers' => ['answers' => [['value' => '4']]], 'grade' => ['score' => 2, 'correct' => true]],
                    'q2' => ['textAnswers' => ['answers' => [['value' => 'صح']]], 'grade' => ['score' => 0, 'correct' => false]],
                ],
            ]]]),
            'forms.googleapis.com/v1/forms' => Http::response(['formId' => 'F1', 'responderUri' => 'https://docs.google.com/forms/d/e/F1/viewform']),
        ]);
    }

    public function test_a_teacher_publishes_a_quiz_form_with_answer_keys(): void
    {
        $this->fakeGoogle();
        $this->actingAs($this->teacher, 'sanctum')->postJson("/api/exam-templates/{$this->template->id}/google-form")
            ->assertCreated()->assertJsonPath('data.responder_uri', 'https://docs.google.com/forms/d/e/F1/viewform');

        Http::assertSent(function ($r) {
            if (! str_ends_with($r->url(), ':batchUpdate')) {
                return false;
            }
            $items = collect($r['requests'])->pluck('createItem.item')->filter()->values();

            return $r['requests'][0]['updateSettings']['settings']['quizSettings']['isQuiz'] === true
                && $items->count() === 4
                && $items[2]['questionItem']['question']['grading']['correctAnswers']['answers'][0]['value'] === '4'
                && $items[3]['questionItem']['question']['grading']['correctAnswers']['answers'][0]['value'] === 'خطأ';
        });
        $this->actingAs($this->teacher, 'sanctum')->postJson("/api/exam-templates/{$this->template->id}/google-form")->assertStatus(409);
    }

    public function test_sync_stores_score_right_wrong_and_matches_the_student_by_email(): void
    {
        $this->fakeGoogle();
        $student = Student::factory()->create(['name' => 'منى علي']);
        $user = User::factory()->create(['role' => 'student', 'email' => 'mona@example.com']);
        \DB::table('student_accounts')->insert(['student_id' => $student->id, 'user_id' => $user->id, 'relationship' => 'student', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->teacher, 'sanctum')->postJson("/api/exam-templates/{$this->template->id}/google-form")->assertCreated();
        $this->actingAs($this->teacher, 'sanctum')->postJson("/api/exam-templates/{$this->template->id}/google-form/sync")
            ->assertOk()->assertJsonPath('new', 1);
        // A second sync neither duplicates nor reports new.
        $this->actingAs($this->teacher, 'sanctum')->postJson("/api/exam-templates/{$this->template->id}/google-form/sync")->assertJsonPath('new', 0);

        $rows = $this->actingAs($this->teacher, 'sanctum')->getJson("/api/exam-templates/{$this->template->id}/google-form/responses")->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($student->id, $rows[0]['student_id']);
        $this->assertEquals(2, $rows[0]['score']);
        $this->assertEquals(3, $rows[0]['max_score']);
        $this->assertTrue($rows[0]['answers'][0]['correct']);
        $this->assertFalse($rows[0]['answers'][1]['correct']);
    }

    public function test_it_asks_to_connect_google_first(): void
    {
        ProviderAccount::query()->delete();
        $this->actingAs($this->teacher, 'sanctum')->postJson("/api/exam-templates/{$this->template->id}/google-form")->assertStatus(422);
    }

    public function test_students_cannot_publish(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'student']), 'sanctum')
            ->postJson("/api/exam-templates/{$this->template->id}/google-form")->assertForbidden();
    }
}
