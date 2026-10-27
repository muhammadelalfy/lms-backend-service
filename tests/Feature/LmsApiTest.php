<?php

namespace Tests\Feature;

use Modules\Students\Models\Student;
use Modules\Auth\Models\User;
use Modules\Learning\Models\Worksheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LmsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_login(): void
    {
        $register = $this->postJson('/api/auth/register', [
            'name' => 'ولي أمر تجريبي',
            'email' => 'parent@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'parent',
        ]);

        $register->assertCreated()->assertJsonPath('user.role', 'parent');
        $this->postJson('/api/auth/login', [
            'email' => 'parent@example.com',
            'password' => 'Password123!',
        ])->assertOk()->assertJsonStructure(['user', 'token']);
    }

    public function test_teacher_can_assign_and_student_can_submit_a_worksheet(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $studentUser = User::factory()->create(['role' => 'student']);
        $student = Student::create(['name' => 'طالب تجريبي', 'group' => 'بنين', 'grade' => 'ثانية إعدادى', 'phone' => '0100000000']);
        $student->account()->create(['user_id' => $studentUser->id, 'relationship' => 'student']);
        $worksheet = Worksheet::create(['title' => 'شيت الجبر', 'subject' => 'الجبر', 'grade' => 'ثانية إعدادى', 'status' => 'published', 'created_by' => $teacher->id]);

        Sanctum::actingAs($teacher);
        $assignment = $this->postJson("/api/worksheets/{$worksheet->id}/assign", ['student_ids' => [$student->id]])
            ->assertOk()->json('assignments.0.id');

        Sanctum::actingAs($studentUser);
        $this->postJson("/api/assignments/{$assignment}/submit", ['score' => 18, 'max_score' => 20])
            ->assertOk()->assertJsonPath('status', 'submitted');
    }

    public function test_student_cannot_create_students_or_view_admin_reports(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'student']));
        $this->postJson('/api/students', [
            'name' => 'غير مصرح', 'group' => 'بنين', 'grade' => 'ثانية إعدادى', 'phone' => '0100000000',
        ])->assertForbidden();
        $this->getJson('/api/reports/summary')->assertForbidden();
    }

    public function test_teacher_can_manage_attendance_and_exam_results(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $student = Student::create(['name' => 'سجل تجريبي', 'group' => 'بنين', 'grade' => 'ثانية إعدادى', 'phone' => '0100000000']);
        Sanctum::actingAs($teacher);

        $attendance = $this->postJson('/api/attendance', [
            'student_id' => $student->id, 'date_at' => '2026-08-15', 'status' => 'present', 'note' => 'في الموعد',
        ])->assertCreated()->json('id');
        $this->putJson("/api/attendance/{$attendance}", ['status' => 'late'])->assertOk()->assertJsonPath('status', 'late');

        $exam = $this->postJson('/api/exams', [
            'student_id' => $student->id, 'title' => 'اختبار الجبر', 'score' => 18, 'max_score' => 20, 'taken_at' => '2026-08-15', 'status' => 'draft',
        ])->assertCreated()->assertJsonPath('status', 'draft')->json('id');
        $this->putJson("/api/exams/{$exam}", ['score' => 19])->assertOk()->assertJsonPath('score', 19);
        $this->deleteJson("/api/attendance/{$attendance}")->assertNoContent();
        $this->deleteJson("/api/exams/{$exam}")->assertNoContent();
    }

    public function test_student_reads_only_linked_attendance_and_cannot_mutate_it(): void
    {
        $studentUser = User::factory()->create(['role' => 'student']);
        $student = Student::create(['name' => 'طالب مقيد', 'group' => 'بنين', 'grade' => 'ثانية إعدادى', 'phone' => '0100000000']);
        $student->account()->create(['user_id' => $studentUser->id, 'relationship' => 'student']);
        Sanctum::actingAs($studentUser);

        $this->getJson('/api/attendance')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/attendance', [
            'student_id' => $student->id, 'date_at' => '2026-08-15', 'status' => 'present',
        ])->assertForbidden();
    }

    public function test_teacher_can_generate_qr_and_scan_attendance_once_per_day(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $student = Student::create(['name' => 'طالب QR', 'group' => 'بنين', 'grade' => 'ثانية إعدادى', 'phone' => '0100000000']);
        Sanctum::actingAs($teacher);

        $payload = $this->getJson("/api/students/{$student->id}/qr")
            ->assertOk()->assertJsonPath('student_id', $student->id)->json('payload');

        $this->postJson('/api/attendance/scan', ['payload' => $payload])
            ->assertCreated()->assertJsonPath('already_recorded', false)->assertJsonPath('attendance.student_id', $student->id);
        $this->postJson('/api/attendance/scan', ['payload' => $payload])
            ->assertOk()->assertJsonPath('already_recorded', true);
    }

    public function test_invalid_qr_is_rejected_and_student_cannot_scan(): void
    {
        $studentUser = User::factory()->create(['role' => 'student']);
        $student = Student::create(['name' => 'طالب ممنوع', 'group' => 'بنين', 'grade' => 'ثانية إعدادى', 'phone' => '0100000000']);
        $student->account()->create(['user_id' => $studentUser->id, 'relationship' => 'student']);
        Sanctum::actingAs($studentUser);

        $this->postJson('/api/attendance/scan', ['payload' => str_repeat('x', 64)])->assertForbidden();
        $this->getJson("/api/students/{$student->id}/qr")->assertOk()->assertJsonPath('student_id', $student->id);
    }

    public function test_role_specific_login_endpoints_reject_wrong_portals(): void
    {
        User::factory()->create(['name' => 'مدير اختبار', 'email' => 'admin@test.local', 'password' => Hash::make('Secret123!'), 'role' => 'admin']);

        $this->postJson('/api/auth/admin/login', ['email' => 'admin@test.local', 'password' => 'Secret123!'])
            ->assertOk()->assertJsonPath('user.role', 'admin')->assertJsonPath('login_type', 'admin');
        $this->postJson('/api/auth/student/login', ['email' => 'admin@test.local', 'password' => 'Secret123!'])
            ->assertStatus(422);
    }

    public function test_only_admin_can_delete_student_and_staff_can_update_profile(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $admin = User::factory()->create(['role' => 'admin']);
        $student = Student::create(['name' => 'ملف قابل للتعديل', 'group' => 'بنين', 'grade' => 'أولى إعدادى', 'phone' => '0100000000']);

        Sanctum::actingAs($teacher);
        $this->putJson("/api/students/{$student->id}", ['name' => 'اسم معدل'])->assertOk()->assertJsonPath('name', 'اسم معدل');
        $this->deleteJson("/api/students/{$student->id}")->assertForbidden();
        Sanctum::actingAs($admin);
        $this->deleteJson("/api/students/{$student->id}")->assertNoContent();
    }
}
