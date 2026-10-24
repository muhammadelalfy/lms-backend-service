<?php

declare(strict_types=1);

namespace Modules\Tenancy\Tests\Feature;

use Illuminate\Support\Facades\URL;
use Modules\Students\Models\Student;
use Tests\TestCase;

/** The operator's page: served on the central address only, carrying no data of its own. */
class OperatorDashboardTest extends TestCase
{
    private const CENTRAL = 'http://localhost';

    private function central(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        URL::forceRootUrl(self::CENTRAL);
        config(['tenancy.operator_token' => 'op-secret']);
    }

    public function test_the_page_is_served_on_the_central_address_without_any_data_and_is_never_cached_or_indexed(): void
    {
        $this->central();

        $response = $this->get(self::CENTRAL.'/operator')->assertOk();

        $response->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertSee('id="gate-form"', false)
            ->assertSee('operator/operator.js', false)
            ->assertSee('name="robots" content="noindex, nofollow"', false)
            ->assertDontSee('op-secret')
            ->assertDontSee('مركز تجريبي')
            ->assertDontSee('demo.mudarisy.test');
    }

    public function test_the_page_lists_the_plans_it_may_offer(): void
    {
        $this->central();

        $this->get(self::CENTRAL.'/operator')->assertOk()
            ->assertSee('"key":"trial"', false)
            ->assertSee('"key":"enterprise"', false)
            ->assertSee('"base_domain":"mudarisy.test"', false);
    }

    public function test_a_school_address_has_no_operator_page(): void
    {
        $this->central();

        $this->get('http://demo.mudarisy.test/operator')->assertNotFound();
    }

    public function test_the_data_it_shows_still_needs_the_operator_token(): void
    {
        $this->central();

        $this->getJson(self::CENTRAL.'/api/central/schools?usage=1')->assertUnauthorized();
        $this->withToken('wrong')->getJson(self::CENTRAL.'/api/central/schools?usage=1')->assertUnauthorized();
    }

    public function test_the_list_adds_each_schools_student_count_only_when_asked(): void
    {
        Student::factory()->count(3)->create();
        $this->central();

        $plain = $this->withToken('op-secret')->getJson(self::CENTRAL.'/api/central/schools')->assertOk();
        $this->assertArrayNotHasKey('students_count', $plain->json('data.0'));

        $this->withToken('op-secret')->getJson(self::CENTRAL.'/api/central/schools?usage=1')->assertOk()
            ->assertJsonPath('data.0.id', 'demo')->assertJsonPath('data.0.students_count', 3);
    }
}
