<?php

declare(strict_types=1);

namespace Modules\Tenancy\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Auth\Models\User;
use Modules\Courses\Models\Course;
use Modules\Payments\Models\Payment;
use Modules\Students\Models\Student;
use Modules\Tenancy\Models\School;
use Tests\TestCase;

/** S19 (backend part): the welcome checklist, derived from the center's data, and the verification banner. */
class OnboardingTest extends TestCase
{
    private const KEYS = ['verify_email', 'add_logo', 'create_course', 'add_teacher', 'enroll_first_student', 'set_payment'];

    private function admin(): User
    {
        $admin = User::factory()->unverified()->create(['role' => 'admin', 'email' => 'owner@demo.test']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function done($response): array
    {
        return collect($response->json('steps'))->filter(fn (array $s) => $s['done'])->pluck('key')->values()->all();
    }

    public function test_a_fresh_self_signed_center_has_six_steps_and_none_done(): void
    {
        $this->school->forceFill(['email_verification_required' => true])->save();
        $this->admin();

        $response = $this->getJson('/api/onboarding')->assertOk()
            ->assertJsonPath('total', 6)
            ->assertJsonPath('done_count', 0)
            ->assertJsonPath('completed', false)
            ->assertJsonPath('dismissed_at', null)
            ->assertJsonPath('visible', true)
            ->assertJsonPath('verification.verified', false)
            ->assertJsonPath('verification.email', 'owner@demo.test')
            ->assertJsonPath('verification.sms_blocked', false)
            ->assertJsonStructure(['steps' => [['key', 'label', 'done', 'href']], 'verification' => ['sms_blocked_from']]);

        $this->assertSame(self::KEYS, collect($response->json('steps'))->pluck('key')->all());
        $this->assertSame([], $this->done($response));
    }

    public function test_each_step_ticks_from_the_centers_own_data(): void
    {
        $this->school->forceFill(['email_verification_required' => true])->save();
        $this->admin();

        $this->school->forceFill(['owner_email_verified_at' => now()->toIso8601String(), 'logo_url' => 'https://cdn.example/l.png'])->save();
        Course::create(['title' => 'الرياضيات', 'slug' => 'math']);
        User::factory()->create(['role' => 'teacher']);
        $this->assertSame(['verify_email', 'add_logo', 'create_course', 'add_teacher'], $this->done($this->getJson('/api/onboarding')));

        $student = Student::factory()->create();
        $this->assertContains('enroll_first_student', $this->done($this->getJson('/api/onboarding')));

        Payment::factory()->create(['student_id' => $student->id, 'recorded_by' => User::factory()->create()->id]);
        $this->getJson('/api/onboarding')->assertJsonPath('done_count', 6)->assertJsonPath('completed', true)->assertJsonPath('visible', false);
    }

    public function test_a_pending_teacher_is_not_yet_a_teacher_and_payment_settings_count_as_set(): void
    {
        $this->admin();
        User::factory()->create(['role' => 'teacher', 'status' => User::STATUS_PENDING]);
        $this->school->forceFill(['fawry_merchant_code' => 'M-1'])->save();

        $done = $this->done($this->getJson('/api/onboarding'));
        $this->assertNotContains('add_teacher', $done);
        $this->assertContains('set_payment', $done);
        // A center opened by the operator was never asked to confirm an email.
        $this->assertContains('verify_email', $done);
    }

    public function test_dismissing_keeps_it_hidden(): void
    {
        $this->admin();

        $this->postJson('/api/onboarding/dismiss')->assertOk()->assertJsonPath('visible', false)
            ->assertJsonPath('dismissed_at', fn ($at) => is_string($at));
        $this->assertNotNull(School::query()->findOrFail('demo')->onboarding_dismissed_at);
        $this->getJson('/api/onboarding')->assertJsonPath('visible', false);
    }

    public function test_the_banner_says_when_sms_stops(): void
    {
        $this->school->forceFill(['email_verification_required' => true])->save();
        $this->admin();

        $this->travel(4)->days();
        $this->getJson('/api/onboarding')->assertJsonPath('verification.sms_blocked', true)
            ->assertJsonPath('verification.sms_blocked_from', fn ($at) => is_string($at));
    }

    public function test_only_the_admin_sees_it(): void
    {
        $this->getJson('/api/onboarding')->assertUnauthorized();
        foreach (['teacher', 'student', 'parent'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/onboarding')->assertForbidden();
            $this->postJson('/api/onboarding/dismiss')->assertForbidden();
        }
    }

    public function test_it_costs_a_handful_of_queries_whatever_the_data(): void
    {
        $this->admin();
        Student::factory()->count(30)->create();
        User::factory()->count(10)->create(['role' => 'teacher']);

        DB::connection('tenant')->enableQueryLog();
        $this->getJson('/api/onboarding')->assertOk();
        $this->assertLessThanOrEqual(8, count(DB::connection('tenant')->getQueryLog()));
    }
}
