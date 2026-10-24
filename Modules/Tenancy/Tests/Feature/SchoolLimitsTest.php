<?php

namespace Modules\Tenancy\Tests\Feature;

use Laravel\Sanctum\Sanctum;
use Modules\Auth\Models\User;
use Modules\Students\Models\Student;
use Modules\Tenancy\Models\School;
use Tests\TestCase;

/** What a school's plan allows, and when a trial locks it. */
class SchoolLimitsTest extends TestCase
{
    public function test_a_limit_comes_from_the_plan_unless_the_school_has_its_own(): void
    {
        $school = new School(['id' => 'x', 'name' => 'x', 'plan' => 't3']);
        $this->assertSame(3, $school->limit('teachers'));
        $this->assertSame(250, $school->limit('students'));
        $this->assertSame(250, $school->limits()['students']);

        $school->limits = ['teachers' => 9, 'subjects' => null];
        $this->assertSame(9, $school->limit('teachers'));
        $this->assertNull($school->limit('subjects'), 'null in the override means unlimited');
        $this->assertSame(8, $school->limit('classes_per_subject'));

        $school->max_students = 40;
        $this->assertSame(40, $school->limit('students'), 'the max_students column wins for students');
        $this->assertSame(40, $school->studentLimit());
    }

    public function test_legacy_plans_still_resolve_their_limits(): void
    {
        $this->assertSame(30, (new School(['plan' => 'trial']))->limit('students'));
        $this->assertSame(200, (new School(['plan' => 'basic']))->limit('students'));
        $this->assertSame(1000, (new School(['plan' => 'pro']))->limit('students'));
        $this->assertNull((new School(['plan' => 'enterprise']))->limit('students'));
        $this->assertNull((new School(['plan' => 'basic']))->limit('teachers'));
        $this->assertNull((new School(['plan' => 'gone']))->limit('students'));
    }

    public function test_a_trial_is_decided_by_its_end_date_not_by_the_plan_name(): void
    {
        $ended = new School(['plan' => 't3', 'status' => 'active', 'trial_ends_at' => now()->subDay()]);
        $this->assertTrue($ended->trialHasEnded());
        $this->assertFalse($ended->isActive());
        $this->assertStringContainsString('مركز', $ended->lockedReason());
        $this->assertStringNotContainsString('المدرسة', $ended->lockedReason());

        $paid = new School(['plan' => 't3', 'status' => 'active', 'trial_ends_at' => null]);
        $this->assertFalse($paid->trialHasEnded());
        $this->assertTrue($paid->isActive());

        $legacy = new School(['plan' => 'trial', 'status' => 'active', 'trial_ends_at' => now()->subDay()]);
        $this->assertTrue($legacy->trialHasEnded());
    }

    public function test_the_student_cap_answers_402_in_words_with_the_center(): void
    {
        $school = $this->school;
        $school->update(['max_students' => 1]);
        Student::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/students', ['name' => 'سارة', 'group' => 'أ', 'grade' => 'الخامس', 'phone' => '+201000000000'])
            ->assertStatus(402)
            ->assertJsonPath('code', 'plan_limit')
            ->assertJsonPath('cap', 'students')
            ->assertJsonPath('limit', 1)
            ->assertJsonPath('used', 1)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'مركزك'));
    }
}
