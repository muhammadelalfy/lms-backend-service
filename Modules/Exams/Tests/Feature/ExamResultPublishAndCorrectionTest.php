<?php

namespace Modules\Exams\Tests\Feature;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Auth\Models\User;
use Modules\Exams\Models\ExamResult;
use Modules\Exams\Models\ExamResultCorrection;
use Modules\Students\Models\Student;
use Modules\Students\Models\StudentAccount;
use Tests\TestCase;

/** S6: draft -> published -> corrected, the audited correction trail, and what students may see. */
class ExamResultPublishAndCorrectionTest extends TestCase
{
    private function linkedStudent(): array
    {
        $student = Student::factory()->create(['group' => 'بلا معلم']);
        $studentUser = User::factory()->create(['role' => 'student']);
        $parentUser = User::factory()->create(['role' => 'parent']);
        StudentAccount::create(['user_id' => $studentUser->id, 'student_id' => $student->id, 'relationship' => 'student']);
        StudentAccount::create(['user_id' => $parentUser->id, 'student_id' => $student->id, 'relationship' => 'parent']);

        return [$student, $studentUser, $parentUser];
    }

    private function body(int $studentId, array $extra = []): array
    {
        return ['student_id' => $studentId, 'title' => 'اختبار الجبر', 'score' => 15, 'max_score' => 20, 'taken_at' => '2026-10-01'] + $extra;
    }

    private function inbox(User $user): int
    {
        return DatabaseNotification::query()->where('notifiable_id', $user->id)->count();
    }

    public function test_a_draft_is_private_freely_editable_and_publishing_tells_the_student_once(): void
    {
        [$student, $studentUser, $parentUser] = $this->linkedStudent();
        $teacher = User::factory()->create(['role' => 'teacher']);
        Sanctum::actingAs($teacher);

        $id = $this->postJson('/api/exams', $this->body($student->id, ['status' => 'draft']))->assertCreated()
            ->assertJsonPath('status', 'draft')->assertJsonPath('published_at', null)->assertJsonPath('student.id', $student->id)->json('id');
        $this->assertSame(0, $this->inbox($studentUser));
        $this->putJson("/api/exams/{$id}", ['score' => 17])->assertOk()->assertJsonPath('score', 17);

        Sanctum::actingAs($studentUser);
        $this->getJson('/api/exams')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/students/{$student->id}")->assertOk()->assertJsonCount(0, 'exam_results');

        Sanctum::actingAs($teacher);
        $this->postJson("/api/exams/{$id}/publish")->assertOk()->assertJsonPath('status', 'published')
            ->assertJsonPath('published_by', $teacher->id)->assertJsonPath('score', 17);
        $this->assertNotNull(ExamResult::find($id)->published_at);
        $this->assertSame(1, $this->inbox($studentUser));
        $this->assertSame(1, $this->inbox($parentUser));
        $this->postJson("/api/exams/{$id}/publish")->assertStatus(409)->assertJsonPath('code', 'already_published');
        $this->assertSame(1, $this->inbox($studentUser));

        Sanctum::actingAs($parentUser);
        $this->getJson('/api/exams')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'published')->assertJsonPath('data.0.last_corrected_at', null);
    }

    public function test_a_published_result_is_never_edited_or_deleted_directly(): void
    {
        [$student] = $this->linkedStudent();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $id = $this->postJson('/api/exams', $this->body($student->id, ['status' => 'published']))->assertCreated()->json('id');

        $this->putJson("/api/exams/{$id}", ['score' => 20])->assertStatus(409)->assertJsonPath('code', 'result_published')
            ->assertJsonPath('message', 'هذه النتيجة منشورة ولا يمكن تعديلها أو حذفها مباشرة؛ يلزم تصحيح موثق من الإدارة.');
        $this->deleteJson("/api/exams/{$id}")->assertStatus(409);
        $this->assertSame(15, ExamResult::find($id)->score);
    }

    public function test_old_clients_that_send_no_status_still_publish_and_notify_at_once(): void
    {
        [$student, $studentUser] = $this->linkedStudent();
        Sanctum::actingAs(User::factory()->create(['role' => 'teacher']));

        $this->postJson('/api/exams', $this->body($student->id))->assertCreated()
            ->assertJsonPath('status', 'published')->assertJsonStructure(['id', 'student_id', 'title', 'score', 'max_score', 'taken_at', 'recorded_by', 'student' => ['id']]);
        $this->assertSame(1, $this->inbox($studentUser));
        $this->postJson('/api/exams', $this->body($student->id, ['status' => 'final']))->assertStatus(422);
    }

    public function test_only_the_admin_corrects_and_only_with_a_reason_and_a_real_change(): void
    {
        [$student, $studentUser, $parentUser] = $this->linkedStudent();
        $teacher = User::factory()->create(['role' => 'teacher']);
        $result = ExamResult::create($this->body($student->id) + ['recorded_by' => $teacher->id, 'status' => 'published', 'published_at' => now()]);
        $url = "/api/exams/{$result->id}/correct";
        $valid = ['score' => 18, 'reason' => 'خطأ في جمع درجات السؤال الثالث'];

        foreach ([$teacher, $studentUser, $parentUser] as $who) {
            Sanctum::actingAs($who);
            $this->postJson($url, $valid)->assertForbidden();
            $this->getJson("/api/exams/{$result->id}/corrections")->assertForbidden();
        }

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson($url, ['score' => 18])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson($url, ['score' => 18, 'reason' => 'قصير'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson($url, ['reason' => 'خطأ في جمع درجات السؤال الثالث'])->assertStatus(422)->assertJsonValidationErrors('changes');
        $this->postJson($url, ['score' => 15, 'reason' => 'خطأ في جمع درجات السؤال الثالث'])->assertStatus(422)->assertJsonValidationErrors('changes');
        $this->assertSame(0, ExamResultCorrection::count());

        $draft = ExamResult::create($this->body($student->id) + ['recorded_by' => $teacher->id]);
        $this->postJson("/api/exams/{$draft->id}/correct", $valid)->assertStatus(409)->assertJsonPath('code', 'result_draft');
    }

    public function test_a_correction_writes_one_audit_row_per_changed_field_and_can_repeat(): void
    {
        [$student, $studentUser] = $this->linkedStudent();
        $other = Student::factory()->create();
        $teacher = User::factory()->create(['role' => 'teacher']);
        $result = ExamResult::create($this->body($student->id) + ['recorded_by' => $teacher->id, 'status' => 'published', 'published_at' => now()]);
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'المدير']);
        Sanctum::actingAs($admin);

        $first = $this->postJson("/api/exams/{$result->id}/correct", [
            'score' => 18, 'title' => 'اختبار الجبر', 'taken_at' => '2026-10-02', 'reason' => 'خطأ في جمع درجات السؤال الثالث',
        ])->assertOk()->assertJsonPath('status', 'corrected')->assertJsonPath('score', 18);
        $group = $first->json('correction_group_id');
        $this->assertNotNull($first->json('last_corrected_at'));

        // The unchanged title is not audited; score and date are.
        $rows = ExamResultCorrection::query()->where('correction_group_id', $group)->orderBy('field')->get();
        $this->assertSame(['score', 'taken_at'], $rows->pluck('field')->all());
        $this->assertSame(['15', '18'], [$rows[0]->old_value, $rows[0]->new_value]);
        $this->assertSame(['2026-10-01', '2026-10-02'], [$rows[1]->old_value, $rows[1]->new_value]);
        $this->assertSame($admin->id, $rows[0]->corrected_by);
        $this->assertSame('خطأ في جمع درجات السؤال الثالث', $rows[0]->reason);
        $this->assertSame(1, DatabaseNotification::query()->where('notifiable_id', $studentUser->id)->where('data', 'like', '%exam_result_corrected%')->count());

        // Recorded against the wrong student: corrected again, appended.
        $this->postJson("/api/exams/{$result->id}/correct", ['student_id' => $other->id, 'reason' => 'سُجلت النتيجة لطالب آخر بالخطأ'])
            ->assertOk()->assertJsonPath('student.id', $other->id);
        $this->assertSame(3, ExamResultCorrection::count());

        $trail = $this->getJson("/api/exams/{$result->id}/corrections")->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame('student_id', $trail->json('data.0.field'));
        $this->assertSame([(string) $student->id, (string) $other->id], [$trail->json('data.0.old_value'), $trail->json('data.0.new_value')]);
        $this->assertSame('المدير', $trail->json('data.0.corrected_by.name'));

        Sanctum::actingAs($studentUser);
        $this->getJson('/api/exams')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_results_recorded_before_the_change_are_backfilled_and_old_code_keeps_publishing(): void
    {
        $student = Student::factory()->create();
        $teacher = User::factory()->create();
        $migration = require __DIR__.'/../../Database/Migrations/2026_10_27_100000_add_publish_state_to_exam_results.php';
        $migration->down();
        DB::table('exam_results')->insert([
            'student_id' => $student->id, 'title' => 'قديم', 'score' => 9, 'max_score' => 10, 'taken_at' => '2026-01-01',
            'recorded_by' => $teacher->id, 'created_at' => '2026-01-02 10:00:00', 'updated_at' => '2026-01-02 10:00:00',
        ]);
        $migration->up();

        $old = DB::table('exam_results')->where('title', 'قديم')->first();
        $this->assertSame('published', $old->status);
        $this->assertSame('2026-01-02 10:00:00', $old->published_at);

        // A row written by the previous release (no status) is still published, as it meant.
        DB::table('exam_results')->insert([
            'student_id' => $student->id, 'title' => 'من النسخة السابقة', 'score' => 5, 'max_score' => 10, 'taken_at' => '2026-01-01',
            'recorded_by' => $teacher->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame('published', DB::table('exam_results')->where('title', 'من النسخة السابقة')->value('status'));
    }
}
