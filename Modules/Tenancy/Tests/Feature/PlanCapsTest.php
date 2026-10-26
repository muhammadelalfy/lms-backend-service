<?php

declare(strict_types=1);

namespace Modules\Tenancy\Tests\Feature;

use Laravel\Sanctum\Sanctum;
use Modules\Auth\Models\User;
use Modules\Students\Models\Student;
use Modules\Tenancy\Models\School;
use Tests\TestCase;

/** The teachers cap of the plan, and what the operator is told when a plan gets smaller than its use. */
class PlanCapsTest extends TestCase
{
    private const CENTRAL = 'http://localhost/api/central';

    private function onPlan(string $plan): void
    {
        $this->school->forceFill(['plan' => $plan])->save();
    }

    private function actAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function assertTeacherCap($response, int $limit, int $used): void
    {
        $response->assertStatus(402)
            ->assertJsonPath('code', 'plan_limit')
            ->assertJsonPath('cap', 'teachers')
            ->assertJsonPath('limit', $limit)
            ->assertJsonPath('used', $used)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'مركزك') && str_contains($m, 'المدرسين'));
    }

    public function test_approving_a_teacher_beyond_the_cap_answers_402_and_changes_nothing(): void
    {
        $this->onPlan('t1'); // one teacher
        User::factory()->create(['role' => 'teacher']);
        $waiting = User::factory()->create(['role' => 'teacher', 'status' => User::STATUS_PENDING]);
        $this->actAsAdmin();

        $this->assertTeacherCap($this->postJson("/api/users/{$waiting->id}/approve"), 1, 1);
        $this->assertTrue($waiting->fresh()->isPending());
    }

    public function test_a_teacher_is_approved_while_under_the_cap(): void
    {
        $this->onPlan('t3'); // three teachers
        User::factory()->count(2)->create(['role' => 'teacher']);
        $waiting = User::factory()->create(['role' => 'teacher', 'status' => User::STATUS_PENDING]);
        $this->actAsAdmin();

        $this->postJson("/api/users/{$waiting->id}/approve")->assertOk()->assertJsonPath('state', 'active');
    }

    public function test_adding_enabling_or_promoting_a_teacher_beyond_the_cap_is_refused(): void
    {
        $this->onPlan('t1');
        User::factory()->create(['role' => 'teacher']);
        $disabled = User::factory()->create(['role' => 'teacher', 'disabled_at' => now()]);
        $this->actAsAdmin();
        $otherAdmin = User::factory()->create(['role' => 'admin']);

        $this->assertTeacherCap($this->postJson('/api/users', ['name' => 'مدرس', 'email' => 'new@demo.test', 'password' => 'Strong!Pass12', 'role' => 'teacher']), 1, 1);
        $this->assertTeacherCap($this->postJson("/api/users/{$disabled->id}/enable"), 1, 1);
        $this->assertTeacherCap($this->patchJson("/api/users/{$otherAdmin->id}/role", ['role' => 'teacher']), 1, 1);

        // An admin is not a teacher seat.
        $this->postJson('/api/users', ['name' => 'مشرف', 'email' => 'admin2@demo.test', 'password' => 'Strong!Pass12', 'role' => 'admin'])->assertCreated();
        $this->assertNull(User::query()->where('email', 'new@demo.test')->first());
        $this->assertNotNull($disabled->fresh()->disabled_at);
    }

    public function test_a_school_override_or_an_unlimited_plan_lifts_the_cap(): void
    {
        $this->onPlan('enterprise');
        User::factory()->count(3)->create(['role' => 'teacher']);
        $waiting = User::factory()->create(['role' => 'teacher', 'status' => User::STATUS_PENDING]);
        $this->actAsAdmin();
        $this->postJson("/api/users/{$waiting->id}/approve")->assertOk();

        $this->school->forceFill(['plan' => 't1', 'limits' => ['teachers' => 10]])->save();
        $another = User::factory()->create(['role' => 'teacher', 'status' => User::STATUS_PENDING]);
        $this->postJson("/api/users/{$another->id}/approve")->assertOk();
    }

    // The operator --------------------------------------------------------------------------

    private function operator(): static
    {
        config(['tenancy.operator_token' => 'op-secret']);
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        return $this->withToken('op-secret');
    }

    public function test_a_downgrade_below_the_usage_is_saved_with_warnings_and_nothing_is_removed(): void
    {
        $this->onPlan('t3');
        Student::factory()->count(3)->create();
        User::factory()->count(2)->create(['role' => 'teacher']);

        $this->operator()->patchJson(self::CENTRAL.'/schools/demo', ['plan' => 't1', 'limits' => ['students' => 2]])->assertOk()
            ->assertJsonPath('plan', 't1')
            ->assertJsonPath('limits.students', 2)
            ->assertJsonPath('limits.teachers', 1)
            ->assertJsonPath('warnings', [
                ['cap' => 'students', 'limit' => 2, 'used' => 3],
                ['cap' => 'teachers', 'limit' => 1, 'used' => 2],
            ]);

        $this->school->run(function () {
            $this->assertSame(3, Student::query()->count());
            $this->assertSame(2, User::query()->where('role', 'teacher')->count());
        });

        // New additions are blocked until the center is under its limit again.
        $this->school->refresh();
        tenancy()->initialize($this->school);
        $this->actAsAdmin();
        $waiting = User::factory()->create(['role' => 'teacher', 'status' => User::STATUS_PENDING]);
        $this->assertTeacherCap($this->postJson("http://demo.mudarisy.test/api/users/{$waiting->id}/approve"), 1, 2);
        $this->postJson('http://demo.mudarisy.test/api/students', ['name' => 'سارة', 'group' => 'أ', 'grade' => 'الخامس', 'phone' => '+201000000000'])
            ->assertStatus(402)->assertJsonPath('cap', 'students');
    }

    public function test_a_change_within_the_usage_has_no_warnings(): void
    {
        $this->onPlan('t1');
        Student::factory()->count(3)->create();

        $this->operator()->patchJson(self::CENTRAL.'/schools/demo', ['plan' => 't4'])->assertOk()
            ->assertJsonPath('warnings', []);
    }

    public function test_activating_a_paid_plan_ends_the_trial(): void
    {
        $this->school->forceFill(['plan' => 't3', 'trial_ends_at' => now()->addDays(2), 'desired_plan' => 't5', 'preferred_billing' => 'yearly'])->save();

        $this->operator()->getJson(self::CENTRAL.'/schools/demo')->assertOk()
            ->assertJsonPath('desired_plan', 't5')
            ->assertJsonPath('preferred_billing', 'yearly');

        $this->operator()->patchJson(self::CENTRAL.'/schools/demo', ['plan' => 't5', 'activate' => true])->assertOk()
            ->assertJsonPath('plan', 't5')
            ->assertJsonPath('trial_ends_at', null);
        $this->assertNull(School::query()->findOrFail('demo')->trial_ends_at);
    }

    public function test_a_limit_override_must_be_a_known_cap_with_a_whole_number(): void
    {
        $this->operator()->patchJson(self::CENTRAL.'/schools/demo', ['limits' => ['gold' => 3]])->assertUnprocessable();
        $this->operator()->patchJson(self::CENTRAL.'/schools/demo', ['limits' => ['teachers' => -1]])->assertUnprocessable();
        $this->operator()->patchJson(self::CENTRAL.'/schools/demo', ['limits' => ['teachers' => null]])->assertOk()
            ->assertJsonPath('limits.teachers', null);
    }
}
