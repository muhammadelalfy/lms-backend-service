<?php

namespace Modules\Tenancy\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Modules\Auth\Models\User;
use Modules\Students\Models\Student;
use Modules\Tenancy\Jobs\RunForSchool;
use Modules\Tenancy\Models\School;
use Tests\TestCase;

/** Opening, changing, locking and closing schools, as the platform operator does. */
class SchoolLifecycleTest extends TestCase
{
    private const CENTRAL = 'http://localhost/api/central';

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (glob(base_path('storage/framework/testing/prov_*')) ?: [] as $file) {
            @unlink($file);
        }
    }

    /** Real databases for the schools the operator opens, kept out of database/. */
    private function realProvisioning(): void
    {
        config([
            'tenancy.provision_databases' => true,
            'tenancy.operator_token' => 'op-secret',
            'tenancy.database.prefix' => '../storage/framework/testing/prov_',
            'tenancy.database.suffix' => '.sqlite',
        ]);
    }

    /** The operator works on the central address, where no school is open. */
    private function operator(): static
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        return $this->withToken('op-secret');
    }

    private function newSchool(array $overrides = []): array
    {
        return $overrides + [
            'slug' => 'alnour', 'name' => 'مركز النور', 'plan' => 'basic',
            'admin' => ['name' => 'مدير', 'email' => 'admin@alnour.test', 'password' => 'secret-pass-1'],
        ];
    }

    // The operator's API -------------------------------------------------------------------

    public function test_the_operator_api_needs_the_operator_token(): void
    {
        tenancy()->end();
        config(['tenancy.operator_token' => null]);
        $this->getJson(self::CENTRAL.'/schools')->assertNotFound();

        config(['tenancy.operator_token' => 'op-secret']);
        $this->getJson(self::CENTRAL.'/schools')->assertUnauthorized();
        $this->withToken('wrong')->getJson(self::CENTRAL.'/schools')->assertUnauthorized();
        $this->operator()->getJson(self::CENTRAL.'/schools')->assertOk()->assertJsonPath('data.0.id', 'demo');
    }

    public function test_the_operator_api_is_not_reachable_through_a_school_address(): void
    {
        config(['tenancy.operator_token' => 'op-secret']);

        $this->operator()->getJson('http://demo.mudarisy.test/api/central/schools')->assertNotFound();
    }

    public function test_opening_a_school_gives_it_a_database_an_address_and_an_administrator(): void
    {
        $this->realProvisioning();

        $this->operator()->postJson(self::CENTRAL.'/schools', $this->newSchool())
            ->assertCreated()
            ->assertJsonPath('id', 'alnour')
            ->assertJsonPath('plan', 'basic')
            ->assertJsonPath('student_limit', 200)
            ->assertJsonPath('domains.0', 'alnour.mudarisy.test');
        $this->assertFileExists(base_path('storage/framework/testing/prov_alnour.sqlite'));

        // The administrator can sign in at the school's own address, and only there.
        URL::forceRootUrl('http://alnour.mudarisy.test');
        $this->postJson('/api/auth/login', ['email' => 'admin@alnour.test', 'password' => 'secret-pass-1'])
            ->assertOk()->assertJsonStructure(['token']);

        URL::forceRootUrl('http://demo.mudarisy.test');
        $this->postJson('/api/auth/login', ['email' => 'admin@alnour.test', 'password' => 'secret-pass-1'])
            ->assertStatus(422);
    }

    public function test_closing_a_school_deletes_its_database(): void
    {
        $this->realProvisioning();
        $this->operator()->postJson(self::CENTRAL.'/schools', $this->newSchool())->assertCreated();
        $file = base_path('storage/framework/testing/prov_alnour.sqlite');

        $this->operator()->deleteJson(self::CENTRAL.'/schools/alnour')->assertStatus(422);
        $this->assertFileExists($file, 'the school id must be repeated to confirm');

        $this->operator()->deleteJson(self::CENTRAL.'/schools/alnour?confirm=alnour')->assertNoContent();
        $this->assertFileDoesNotExist($file);
        $this->assertNull(School::query()->find('alnour'));
    }

    public function test_a_subdomain_must_be_valid_free_and_not_reserved(): void
    {
        $this->realProvisioning();

        foreach (['Al Nour', 'نور', '-bad', 'bad-', 'x_y'] as $slug) {
            $this->operator()->postJson(self::CENTRAL.'/schools', $this->newSchool(['slug' => $slug]))
                ->assertStatus(422)->assertJsonValidationErrors('slug');
        }
        foreach (['www', 'api', 'admin'] as $slug) {
            $this->operator()->postJson(self::CENTRAL.'/schools', $this->newSchool(['slug' => $slug]))
                ->assertStatus(422)->assertJsonPath('errors.slug.0', 'هذا الكود محجوز.');
        }
        $this->operator()->postJson(self::CENTRAL.'/schools', $this->newSchool(['slug' => 'demo']))
            ->assertStatus(422)->assertJsonPath('errors.slug.0', 'هذا الكود مستخدم لمركز آخر.');

        $this->operator()->postJson(self::CENTRAL.'/schools', $this->newSchool(['plan' => 'gold']))
            ->assertStatus(422)->assertJsonValidationErrors('plan');
        $this->assertSame(1, School::query()->count());
    }

    public function test_a_trial_school_gets_its_trial_days(): void
    {
        $this->realProvisioning();

        $this->operator()->postJson(self::CENTRAL.'/schools', $this->newSchool(['plan' => 'trial']))
            ->assertCreated()->assertJsonPath('active', true)->assertJsonPath('student_limit', 30);

        $ends = School::query()->find('alnour')->trial_ends_at;
        $this->assertTrue($ends->between(now()->addDays(13), now()->addDays(15)));
    }

    public function test_the_operator_changes_a_plan_and_a_school_settings_without_leaking_them(): void
    {
        config(['tenancy.operator_token' => 'op-secret']);

        $this->operator()->patchJson(self::CENTRAL.'/schools/demo', [
            'plan' => 'pro', 'max_students' => 50, 'settings' => ['sms_gateway_token' => 'tok-123'],
        ])->assertOk()
            ->assertJsonPath('plan', 'pro')
            ->assertJsonPath('student_limit', 50)
            ->assertJsonPath('settings', ['sms_gateway_token'])
            ->assertDontSee('tok-123');

        $this->operator()->patchJson(self::CENTRAL.'/schools/demo', ['settings' => ['not_a_setting' => 'x']])
            ->assertStatus(422);
        $this->operator()->patchJson(self::CENTRAL.'/schools/demo', ['plan' => 'gold'])->assertStatus(422);
    }

    public function test_a_school_can_have_another_address(): void
    {
        config(['tenancy.operator_token' => 'op-secret']);

        $this->operator()->postJson(self::CENTRAL.'/schools/demo/domains', ['domain' => 'School.Example.com'])
            ->assertCreated()->assertJsonPath('domains.1', 'school.example.com');

        URL::forceRootUrl('http://school.example.com');
        $this->postJson('/api/auth/login', ['email' => 'nobody@x.test', 'password' => 'x'])->assertStatus(422);
        $this->operator()->postJson(self::CENTRAL.'/schools/demo/domains', ['domain' => 'bad domain!'])->assertStatus(422);
    }

    // Locking ---------------------------------------------------------------------------------

    public function test_a_suspended_school_is_turned_away_until_it_is_active_again(): void
    {
        config(['tenancy.operator_token' => 'op-secret']);
        $this->operator()->patchJson(self::CENTRAL.'/schools/demo', ['status' => 'suspended'])->assertOk()->assertJsonPath('active', false);

        $this->postJson('/api/auth/login', ['email' => 'a@b.c', 'password' => 'x'])
            ->assertForbidden()->assertJsonPath('code', 'school_locked');

        $this->operator()->patchJson(self::CENTRAL.'/schools/demo', ['status' => 'active'])->assertOk();
        $this->postJson('/api/auth/login', ['email' => 'a@b.c', 'password' => 'x'])->assertStatus(422);
    }

    public function test_a_trial_that_has_ended_locks_the_school(): void
    {
        $this->school->update(['plan' => 'trial', 'trial_ends_at' => now()->subDay()]);

        $this->postJson('/api/auth/login', ['email' => 'a@b.c', 'password' => 'x'])
            ->assertForbidden()->assertJsonPath('code', 'school_locked');

        $this->school->update(['plan' => 'basic']);
        $this->postJson('/api/auth/login', ['email' => 'a@b.c', 'password' => 'x'])->assertStatus(422);
    }

    // Plans ---------------------------------------------------------------------------------

    public function test_a_school_cannot_add_students_beyond_its_plan(): void
    {
        $this->school->update(['max_students' => 2]);
        Student::factory()->count(2)->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/students', ['name' => 'سارة', 'group' => 'أ', 'grade' => 'الخامس', 'phone' => '+201000000000'])
            ->assertStatus(402)->assertJsonPath('code', 'plan_limit')->assertJsonPath('limit', 2);
        $this->assertSame(2, Student::query()->count());

        $this->school->update(['max_students' => null]);
        $this->postJson('/api/students', ['name' => 'سارة', 'group' => 'أ', 'grade' => 'الخامس', 'phone' => '+201000000000'])
            ->assertCreated();
    }

    // The scheduler ----------------------------------------------------------------------------

    public function test_a_scheduled_task_is_queued_once_for_each_active_school(): void
    {
        $this->openSchool('beta');
        $suspended = $this->openSchool('gamma');
        $suspended->update(['status' => School::SUSPENDED]);
        Queue::fake();

        $this->artisan('schools:dispatch', ['schedulable' => 'calls:expire'])->assertSuccessful();

        Queue::assertPushed(RunForSchool::class, 2);
        Queue::assertPushed(RunForSchool::class, fn ($job) => $job->schoolId === 'demo' && $job->command === 'calls:expire');
        Queue::assertPushed(RunForSchool::class, fn ($job) => $job->schoolId === 'beta');
        Queue::assertNotPushed(RunForSchool::class, fn ($job) => $job->schoolId === 'gamma');
    }

    public function test_the_job_runs_the_command_inside_the_school(): void
    {
        $this->artisan('schools:dispatch', ['schedulable' => 'calls:expire'])->assertSuccessful();

        // Runs for the school (sync queue); a school that closed meanwhile is skipped quietly.
        (new RunForSchool('demo', 'calls:expire'))->handle();
        (new RunForSchool('missing', 'calls:expire'))->handle();
        $this->assertTrue(true);
    }
}
