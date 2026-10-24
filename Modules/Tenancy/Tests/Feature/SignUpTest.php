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
        $this->assertSame('trial', $school->plan);
        $this->assertSame('center', $school->kind);
        $this->assertNotNull($school->trial_ends_at);
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
