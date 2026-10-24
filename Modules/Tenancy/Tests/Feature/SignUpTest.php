<?php

declare(strict_types=1);

namespace Modules\Tenancy\Tests\Feature;

use Modules\Tenancy\Models\School;
use Tests\TestCase;

/** A stranger opens a trial academy from the public site. */
class SignUpTest extends TestCase
{
    private const URL = 'http://localhost/api/signup';

    private function body(array $overrides = []): array
    {
        return $overrides + [
            'kind' => 'center', 'name' => 'سنتر النور', 'slug' => 'alnour',
            'admin' => ['name' => 'أحمد', 'email' => 'owner@alnour.test', 'password' => 'Strong!Pass1'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Real databases for the academies opened here, kept out of database/.
        config([
            'tenancy.provision_databases' => true,
            'tenancy.database.prefix' => '../storage/framework/testing/prov_',
            'tenancy.database.suffix' => '.sqlite',
        ]);
        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (glob(base_path('storage/framework/testing/prov_*')) ?: [] as $file) {
            @unlink($file);
        }
    }

    public function test_it_opens_a_trial_academy_and_points_to_its_sign_in(): void
    {
        $this->postJson(self::URL, $this->body())->assertCreated()
            ->assertJsonPath('name', 'سنتر النور')
            ->assertJsonPath('url', 'http://alnour.'.config('tenancy.base_domain').'/login')
            ->assertJsonStructure(['trial_ends_at']);

        $school = School::query()->findOrFail('alnour');
        $this->assertSame('t3', $school->plan);
        $this->assertSame('center', $school->kind);
        $this->assertNotNull($school->trial_ends_at);
    }

    public function test_the_chosen_plan_is_clamped_to_the_trial_max_and_remembered(): void
    {
        $this->postJson(self::URL, $this->body(['plan' => 't5', 'billing' => 'yearly']))->assertCreated()
            ->assertJsonPath('plan', 't3')
            ->assertJsonPath('desired_plan', 't5')
            ->assertJsonPath('limits.students', 250)
            ->assertJsonPath('limits.teachers', 3);

        $school = School::query()->findOrFail('alnour');
        $this->assertSame('t3', $school->plan);
        $this->assertSame('t5', $school->desired_plan);
        $this->assertSame('yearly', $school->preferred_billing);
        $this->assertTrue($school->trial_ends_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));
    }

    public function test_the_trial_length_comes_from_config(): void
    {
        config(['schools.trial_days' => 3]);
        $this->postJson(self::URL, $this->body())->assertCreated();

        $this->assertTrue(School::query()->findOrFail('alnour')->trial_ends_at->between(now()->addDays(3)->subMinute(), now()->addDays(3)->addMinute()));
    }

    public function test_a_plan_below_the_trial_max_is_used_as_chosen(): void
    {
        $this->postJson(self::URL, $this->body(['plan' => 't2']))->assertCreated()
            ->assertJsonPath('plan', 't2')->assertJsonPath('desired_plan', 't2')->assertJsonPath('limits.students', 100);
    }

    public function test_without_a_plan_the_audiences_trial_plan_is_used(): void
    {
        $this->postJson(self::URL, $this->body(['kind' => 'solo']))->assertCreated()
            ->assertJsonPath('plan', 't1')->assertJsonPath('desired_plan', 't1');
        $this->assertSame('monthly', School::query()->findOrFail('alnour')->preferred_billing);
    }

    public function test_a_plan_that_is_not_public_is_refused(): void
    {
        $before = School::query()->count();
        foreach (['trial', 'enterprise', 'gold'] as $plan) {
            $this->postJson(self::URL, $this->body(['plan' => $plan]))
                ->assertUnprocessable()->assertJsonPath('errors.plan.0', 'الباقة المختارة غير متاحة.');
        }
        $this->postJson(self::URL, $this->body(['billing' => 'weekly']))->assertJsonValidationErrors('billing');
        $this->assertSame($before, School::query()->count());
    }

    public function test_a_taken_or_reserved_address_is_refused_with_the_reason(): void
    {
        $this->postJson(self::URL, $this->body())->assertCreated();

        $this->postJson(self::URL, $this->body())->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->postJson(self::URL, $this->body(['slug' => 'admin']))->assertUnprocessable()->assertJsonValidationErrors('slug');
    }

    public function test_it_needs_a_known_kind_a_real_email_and_a_strong_enough_password(): void
    {
        $this->postJson(self::URL, $this->body(['kind' => 'bank']))->assertJsonValidationErrors('kind');
        $this->postJson(self::URL, $this->body(['admin' => ['name' => 'أ', 'email' => 'x', 'password' => '123']]))
            ->assertJsonValidationErrors(['admin.email', 'admin.password']);
    }

    public function test_a_bot_that_fills_the_hidden_field_is_refused_and_nothing_is_made(): void
    {
        $before = School::query()->count();

        $this->postJson(self::URL, $this->body(['website' => 'http://spam.example']))->assertUnprocessable();

        $this->assertSame($before, School::query()->count());
    }

    public function test_it_is_limited_per_visitor(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::URL, $this->body(['slug' => 'center-'.$i]));
        }

        $this->postJson(self::URL, $this->body(['slug' => 'one-too-many']))->assertStatus(429);
    }

    public function test_it_is_not_reachable_on_a_schools_own_address(): void
    {
        $this->postJson('http://demo.'.config('tenancy.base_domain').'/api/signup', $this->body())->assertNotFound();
    }
}
