<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LandingPricingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_the_central_landing_renders_the_pricing_section_on_the_server(): void
    {
        $html = $this->get('http://localhost/')->assertOk()
            ->assertSee('id="pricing"', false)
            ->assertSee('باقة على مقاس مركزك')
            ->assertSee('landing/pricing.js', false)
            ->assertSee('landing/pricing.css', false)
            ->getContent();

        // Plan names and prices come straight from the catalog (t4: 2600 list, 1560 shown, 15600 a year).
        foreach (['تقدّم', 'تميّز', 'احتراف', 'ريادة', 'قمة', 'امتداد', 'انطلاقة', 'نمو'] as $name) {
            $this->assertStringContainsString($name, $html);
        }
        $this->assertStringContainsString('<strong>1,560</strong>', $html);
        $this->assertStringContainsString('<strong>15,600</strong>', $html);
        $this->assertStringContainsString('2,600', $html);
        $this->assertStringContainsString('السعر قبل الخصم', $html);
        $this->assertStringContainsString('خصم الإطلاق 40%', $html);
        $this->assertStringContainsString('7 أيام', $html);
        $this->assertStringContainsString('شاملة ضريبة القيمة المضافة', $html);
    }

    public function test_it_is_complete_without_javascript(): void
    {
        $html = $this->get('http://localhost/')->assertOk()->getContent();

        foreach (['solo', 'group', 'center'] as $audience) {
            $this->assertStringContainsString('id="pr-panel-'.$audience.'"', $html);
            $this->assertStringContainsString('href="#pr-panel-'.$audience.'"', $html);
        }
        $this->assertStringContainsString('<table class="pr-table">', $html);
        $this->assertStringContainsString('scope="colgroup"', $html);
        $this->assertSame(6, substr_count($html, '<details data-faq='));
        $this->assertStringContainsString('إزاي التجربة المجانية شغالة؟', $html);
        $this->assertStringContainsString('ابدأ تجربتك المجانية', $html);
        $this->assertStringContainsString('ابدأ بالباقة دي', $html);
        $this->assertStringContainsString('id="plans-data"', $html);
        $this->assertStringContainsString('id="signup"', $html);
        // "soon" is never a tick.
        $this->assertStringContainsString('class="pr-soon">قريباً</td>', $html);
    }

    public function test_the_signup_dialog_has_plan_and_billing_fields_and_the_honeypot(): void
    {
        $this->get('http://localhost/')->assertOk()
            ->assertSee('id="signup-dialog"', false)
            ->assertSee('name="plan"', false)
            ->assertSee('name="billing"', false)
            ->assertSee('name="website"', false);
    }

    public function test_the_new_section_never_says_school_or_academy(): void
    {
        $html = $this->get('http://localhost/')->assertOk()->getContent();
        $start = strpos($html, '<section id="pricing"');
        $section = substr($html, $start, strpos($html, '</section>', $start) - $start);

        $this->assertStringNotContainsString('مدرسة', $section);
        $this->assertStringNotContainsString('أكاديمية', $section);
    }

    public function test_the_section_follows_the_catalog_when_the_promo_is_off(): void
    {
        config(['schools.pricing.promo_percent' => 0]);

        $html = $this->get('http://localhost/')->assertOk()->getContent();

        $this->assertStringContainsString('<strong>2,600</strong>', $html);
        $this->assertStringNotContainsString('السعر قبل الخصم', $html);
        $this->assertStringNotContainsString('pr-badge-promo">', $html);
    }

    public function test_a_center_address_has_no_pricing_and_no_signup_dialog(): void
    {
        $this->get('http://demo.mudarisy.test/')->assertOk()
            ->assertDontSee('id="pricing"', false)
            ->assertDontSee('id="signup-dialog"', false)
            ->assertDontSee('landing/pricing.js', false)
            ->assertDontSee('باقة على مقاس مركزك');
    }
}
