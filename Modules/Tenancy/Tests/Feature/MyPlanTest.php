<?php

declare(strict_types=1);

namespace Modules\Tenancy\Tests\Feature;

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Modules\Auth\Models\User;
use Modules\Students\Models\Student;
use Modules\Tenancy\Models\PlanRequest;
use Modules\Tenancy\Notifications\PlanRequestReceived;
use Tests\TestCase;

/** "باقتي": a center's admin sees the plan, its usage, and asks for an upgrade. */
class MyPlanTest extends TestCase
{
    private function onPlan(string $plan, array $extra = []): void
    {
        $this->school->forceFill(['plan' => $plan] + $extra)->save();
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'مدير النور', 'email' => 'owner@demo.test']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_the_admin_sees_plan_trial_limits_usage_and_the_next_plan(): void
    {
        $this->onPlan('t3', ['trial_ends_at' => now()->addDays(3)->addHour(), 'desired_plan' => 't5']);
        Student::factory()->count(4)->create();
        User::factory()->count(2)->create(['role' => 'teacher']);
        User::factory()->create(['role' => 'teacher', 'status' => User::STATUS_PENDING]);
        User::factory()->create(['role' => 'teacher', 'disabled_at' => now()]);
        $this->admin();

        $this->getJson('/api/my-plan')->assertOk()
            ->assertJsonPath('plan', ['slug' => 't3', 'name' => 'تقدّم'])
            ->assertJsonPath('trial', true)
            ->assertJsonPath('days_left', 4)
            ->assertJsonPath('locked', false)
            ->assertJsonPath('desired_plan', 't5')
            ->assertJsonPath('limits.students', 250)
            ->assertJsonPath('limits.teachers', 3)
            ->assertJsonPath('usage.students', 4)
            ->assertJsonPath('usage.teachers', 2)
            ->assertJsonPath('usage.subjects', null)
            ->assertJsonPath('usage.assistants', null)
            ->assertJsonPath('usage.storage_gb', null)
            ->assertJsonPath('next_plan.slug', 't4')
            ->assertJsonPath('next_plan.name', 'تميّز')
            ->assertJsonPath('next_plan.limits.students', 500)
            ->assertJsonPath('next_plan.price.shown_monthly', 1560)
            ->assertJsonStructure(['trial_ends_at', 'limits' => ['students', 'teachers', 'subjects', 'classes_per_subject', 'assistants', 'storage_gb']]);
    }

    public function test_a_paid_top_plan_has_no_trial_and_no_next_plan(): void
    {
        $this->onPlan('t8', ['trial_ends_at' => null]);
        $this->admin();

        $this->getJson('/api/my-plan')->assertOk()
            ->assertJsonPath('trial', false)
            ->assertJsonPath('trial_ends_at', null)
            ->assertJsonPath('days_left', null)
            ->assertJsonPath('next_plan', null);
    }

    public function test_a_legacy_plan_points_to_the_first_public_plan_that_holds_more_students(): void
    {
        $this->onPlan('basic');
        $this->admin();

        $this->getJson('/api/my-plan')->assertOk()
            ->assertJsonPath('plan', ['slug' => 'basic', 'name' => 'أساسية'])
            ->assertJsonPath('limits.students', 200)
            ->assertJsonPath('next_plan.slug', 't3');
    }

    public function test_only_the_admin_may_read_it(): void
    {
        $this->getJson('/api/my-plan')->assertUnauthorized();
        foreach (['teacher', 'student', 'parent'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/my-plan')->assertForbidden();
            $this->postJson('/api/my-plan/upgrade-request')->assertForbidden();
        }
    }

    public function test_the_admin_asks_for_an_upgrade_and_the_operator_inbox_gets_it(): void
    {
        Notification::fake();
        config(['landing.contact_email' => 'sales@mudarisy.test']);
        $this->onPlan('t3');
        $this->admin();

        $this->postJson('/api/my-plan/upgrade-request', ['plan_slug' => 't5', 'message' => 'عندنا 400 طالب جديد'])->assertCreated()
            ->assertJsonPath('message', 'وصل طلبك وهنكلمك قريب')
            ->assertJsonPath('request.plan_slug', 't5')
            ->assertJsonPath('request.status', 'new');

        $row = PlanRequest::query()->sole();
        $this->assertSame('upgrade', $row->type);
        $this->assertSame('demo', $row->school_id);
        $this->assertSame('مدير النور', $row->name);
        $this->assertSame('owner@demo.test', $row->email);
        $this->assertSame('عندنا 400 طالب جديد', $row->message);
        Notification::assertSentOnDemandTimes(PlanRequestReceived::class, 1);

        // Asked again the same day: the same request, not a second one.
        $this->postJson('/api/my-plan/upgrade-request', ['plan_slug' => 't5'])->assertCreated();
        $this->assertSame(1, PlanRequest::query()->count());
    }

    public function test_without_a_plan_the_next_one_is_asked_for_with_a_default_message(): void
    {
        Notification::fake();
        $this->onPlan('t3');
        $this->admin();

        $this->postJson('/api/my-plan/upgrade-request')->assertCreated()->assertJsonPath('request.plan_slug', 't4');
        $this->assertStringContainsString('t4', PlanRequest::query()->sole()->message);
    }

    public function test_an_unknown_plan_or_a_long_message_is_refused(): void
    {
        $this->admin();

        $this->postJson('/api/my-plan/upgrade-request', ['plan_slug' => 'gold'])->assertUnprocessable()
            ->assertJsonPath('errors.plan_slug.0', 'الباقة المختارة غير متاحة.');
        $this->postJson('/api/my-plan/upgrade-request', ['message' => str_repeat('ا', 801)])->assertUnprocessable()
            ->assertJsonValidationErrors('message');
    }

    public function test_upgrade_requests_are_limited_to_three_an_hour(): void
    {
        Notification::fake();
        $this->admin();

        foreach (['t4', 't5', 't6'] as $plan) {
            $this->postJson('/api/my-plan/upgrade-request', ['plan_slug' => $plan])->assertCreated();
        }
        $this->postJson('/api/my-plan/upgrade-request', ['plan_slug' => 't7'])->assertStatus(429)
            ->assertJsonPath('message', 'طلبات كتير في وقت قصير، جرّب بعد شوية.');
    }

    public function test_a_demo_admin_cannot_ask_for_an_upgrade(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_demo' => true]));

        $this->getJson('/api/my-plan')->assertOk();
        $this->postJson('/api/my-plan/upgrade-request')->assertForbidden();
    }

    public function test_one_center_never_sees_another_centers_plan_or_requests(): void
    {
        Notification::fake();
        $this->onPlan('t3');
        Student::factory()->count(2)->create();
        $token = User::factory()->create(['role' => 'admin'])->createToken('app')->plainTextToken;

        $beta = $this->openSchool('beta', 't6');
        $betaAdmin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($betaAdmin);
        $this->getJson('http://beta.mudarisy.test/api/my-plan')->assertOk()
            ->assertJsonPath('plan.slug', 't6')->assertJsonPath('usage.students', 0);
        $this->postJson('http://beta.mudarisy.test/api/my-plan/upgrade-request')->assertCreated();
        $this->assertSame(['beta'], PlanRequest::query()->pluck('school_id')->all());

        // Demo's token on beta's address is nobody.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('http://beta.mudarisy.test/api/my-plan')->assertUnauthorized();

        tenancy()->initialize($this->school);
        URL::forceRootUrl('http://demo.mudarisy.test');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('http://demo.mudarisy.test/api/my-plan')->assertOk()
            ->assertJsonPath('plan.slug', 't3')->assertJsonPath('usage.students', 2);
        $this->assertNotSame($beta->id, tenant('id'));
    }
}
