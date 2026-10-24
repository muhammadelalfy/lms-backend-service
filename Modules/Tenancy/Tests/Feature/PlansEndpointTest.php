<?php

namespace Modules\Tenancy\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Modules\Tenancy\Services\PlanCatalog;
use Tests\TestCase;

/** GET /api/plans: the public catalog for the landing page and the web app. */
class PlansEndpointTest extends TestCase
{
    private const URL = 'http://localhost/api/plans';

    public function test_it_lists_the_public_catalog(): void
    {
        $response = $this->getJson(self::URL)->assertOk()
            ->assertHeader('Cache-Control')
            ->assertJsonPath('currency', 'EGP')
            ->assertJsonPath('trial_days', 7)
            ->assertJsonPath('vat_included', true)
            ->assertJsonPath('yearly_months_billed', 10)
            ->assertJsonPath('promo.percent', 40)
            ->assertJsonPath('promo.active', true)
            ->assertJsonPath('promo.ends_at', null)
            ->assertJsonPath('addons.extra_student', ['list' => 5, 'shown' => 3])
            ->assertJsonPath('addons.extra_storage_gb', ['list' => 0.5, 'shown' => 0.3])
            ->assertJsonPath('plans.0.slug', 't1')
            ->assertJsonPath('plans.0.name', 'انطلاقة')
            ->assertJsonPath('plans.0.price.shown_yearly', 3000)
            ->assertJsonPath('plans.0.limits.students', 50)
            ->assertJsonPath('plans.2.features.qr_face_attendance', true)
            ->assertJsonPath('plans.2.features.installments', 'soon')
            ->assertJsonPath('audiences.0.key', 'center')
            ->assertJsonPath('audiences.0.trial_plan', 't3')
            ->assertJsonStructure([
                'plans' => [['slug', 'tier', 'name', 'limits', 'price' => ['list_monthly', 'shown_monthly', 'shown_yearly', 'yearly_per_month'], 'features']],
                'audiences' => [['key', 'label', 'intro', 'kind', 'plans', 'trial_plan', 'recommended']],
                'feature_rows' => [['group', 'rows' => [['key', 'label', 'type']]]],
            ]);

        $this->assertStringContainsString('max-age=300', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
    }

    public function test_legacy_plans_are_not_listed_but_still_resolve_their_limits(): void
    {
        $slugs = collect($this->getJson(self::URL)->json('plans'))->pluck('slug')->all();

        $this->assertSame(['t1', 't2', 't3', 't4', 't5', 't6', 't7', 't8'], $slugs);
        $this->assertSame(200, config('schools.plans.basic.limits.students'));
    }

    public function test_it_is_cached(): void
    {
        Cache::flush();
        $this->getJson(self::URL)->assertOk();
        $this->assertTrue(Cache::has(PlanCatalog::cacheKey()));
    }

    public function test_it_is_not_reachable_on_a_schools_own_address(): void
    {
        $this->getJson('http://demo.'.config('tenancy.base_domain').'/api/plans')->assertNotFound();
    }
}
