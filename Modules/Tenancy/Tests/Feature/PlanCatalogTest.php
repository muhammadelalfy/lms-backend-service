<?php

namespace Modules\Tenancy\Tests\Feature;

use Modules\Tenancy\Services\PlanCatalog;
use Tests\TestCase;

/** The plan ladder is data in config; these tests keep it a sane, monotonic ladder. */
class PlanCatalogTest extends TestCase
{
    private function catalog(): PlanCatalog
    {
        return app(PlanCatalog::class);
    }

    public function test_the_public_ladder_is_monotonic(): void
    {
        $plans = array_values($this->catalog()->publicPlans());
        $this->assertSame(['t1', 't2', 't3', 't4', 't5', 't6', 't7', 't8'], array_column($plans, 'slug'));

        for ($i = 1; $i < count($plans); $i++) {
            $prev = $plans[$i - 1];
            $cur = $plans[$i];
            $this->assertGreaterThan($prev['tier'], $cur['tier']);
            $this->assertGreaterThan($prev['limits']['students'], $cur['limits']['students'], "students {$cur['slug']}");
            $this->assertGreaterThan($prev['price']['list_monthly'], $cur['price']['list_monthly'], "price {$cur['slug']}");
            $this->assertLessThan(
                $prev['price']['list_monthly'] / $prev['limits']['students'],
                $cur['price']['list_monthly'] / $cur['limits']['students'],
                "price per student {$cur['slug']}",
            );
            foreach (['teachers', 'subjects', 'classes_per_subject', 'assistants', 'storage_gb'] as $cap) {
                $this->assertGreaterThanOrEqual($prev['limits'][$cap], $cur['limits'][$cap], "{$cap} {$cur['slug']}");
            }
        }
    }

    public function test_every_audience_points_at_real_public_plans(): void
    {
        $public = array_keys($this->catalog()->publicPlans());
        foreach ($this->catalog()->audiences() as $audience) {
            $this->assertNotEmpty(array_intersect($audience['plans'], $public));
            $this->assertEqualsCanonicalizing($audience['plans'], array_intersect($audience['plans'], $public));
            $this->assertContains($audience['trial_plan'], $audience['plans']);
            $this->assertContains($audience['recommended'], $audience['plans']);
        }
    }

    public function test_shown_and_yearly_prices_derive_from_the_list_price(): void
    {
        $t1 = $this->catalog()->plan('t1');
        $this->assertSame(['list_monthly' => 500, 'shown_monthly' => 300, 'shown_yearly' => 3000, 'yearly_per_month' => 250], $t1['price']);
        $this->assertSame(16800, $this->catalog()->plan('t8')['price']['shown_monthly']);
        $this->assertSame(168000, $this->catalog()->plan('t8')['price']['shown_yearly']);
    }

    public function test_no_promo_or_an_ended_promo_shows_the_list_price(): void
    {
        config(['schools.pricing.promo_percent' => 0]);
        $this->assertSame(500, $this->catalog()->plan('t1')['price']['shown_monthly']);
        $this->assertSame(5000, $this->catalog()->plan('t1')['price']['shown_yearly']);
        $this->assertFalse($this->catalog()->promo()['active']);

        config(['schools.pricing.promo_percent' => 40, 'schools.pricing.promo_ends_at' => now()->subDay()->toDateString()]);
        $this->assertSame(500, $this->catalog()->plan('t1')['price']['shown_monthly']);
        $this->assertFalse($this->catalog()->promo()['active']);

        config(['schools.pricing.promo_ends_at' => now()->addDay()->toDateString()]);
        $this->assertSame(300, $this->catalog()->plan('t1')['price']['shown_monthly']);
        $this->assertTrue($this->catalog()->promo()['active']);
    }

    public function test_buying_extra_students_is_never_cheaper_than_the_next_tier_per_student(): void
    {
        $rate = config('schools.pricing.addons.extra_student');
        $plans = array_values($this->catalog()->publicPlans());
        // t1 -> t2 is the one step that is steeper than the rate (6/student); the spec's claim holds from t2 on.
        for ($i = 2; $i < count($plans); $i++) {
            $step = ($plans[$i]['price']['list_monthly'] - $plans[$i - 1]['price']['list_monthly'])
                / ($plans[$i]['limits']['students'] - $plans[$i - 1]['limits']['students']);
            $this->assertGreaterThan($step, $rate, "marginal step into {$plans[$i]['slug']}");
        }
    }

    public function test_the_trial_plan_is_clamped_to_trial_max_plan(): void
    {
        $this->assertSame('t3', $this->catalog()->effectiveTrialPlan('t5'));
        $this->assertSame('t3', $this->catalog()->effectiveTrialPlan('t3'));
        $this->assertSame('t2', $this->catalog()->effectiveTrialPlan('t2'));
    }
}
