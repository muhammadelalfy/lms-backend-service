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

    public function test_the_calculator_is_server_rendered_from_the_catalog(): void
    {
        $html = $this->get('http://localhost/')->assertOk()
            ->assertSee('id="pr-calc"', false)
            ->assertSee('احسب باقتك بالإضافات')
            ->assertSee('الباقة الأساسية')
            ->assertSee('طلاب إضافيين')
            ->assertSee('مساحة تخزين إضافية (جيجا)')
            ->assertSee('الإجمالي الشهري')
            ->assertSee('الإجمالي السنوي')
            ->assertSee('الباقة الأعلى أوفر لك')
            ->getContent();

        // Labelled number fields bound to ranges; the total is announced politely.
        $this->assertStringContainsString('<label for="pc-plan">', $html);
        $this->assertStringContainsString('<label for="pc-students">', $html);
        $this->assertStringContainsString('<label for="pc-storage">', $html);
        $this->assertStringContainsString('id="pc-students-range"', $html);
        $this->assertStringContainsString('id="pc-storage-range" min="0" max="2000"', $html);
        $this->assertStringContainsString('id="pc-total" aria-live="polite"', $html);
        // Start state: the center tab's recommended plan (t4, 1,560 shown), up to the next tier (t5: 1000 - 500).
        $this->assertStringContainsString('<option value="t4" selected>', $html);
        $this->assertStringContainsString('id="pc-students" name="extra_students" class="su-input" min="0" max="500"', $html);
        $this->assertStringContainsString('<span id="pc-monthly">1,560</span>', $html);
        $this->assertStringContainsString('<span id="pc-yearly">15,600</span>', $html);
        // The rates the JS multiplies by are the catalog's (embedded too).
        $this->assertStringContainsString('بـ5 جنيه للطالب', $html);
        $this->assertStringContainsString('بـ0.5 جنيه للجيجا', $html);
        $this->assertStringContainsString('"extra_student":{"list":5', $html);
    }

    public function test_every_tab_has_a_custom_plan_card_and_the_form_is_on_the_page(): void
    {
        config(['landing.contact_email' => 'hello@mudarisy.test']);

        $html = $this->get('http://localhost/')->assertOk()
            ->assertSee('id="custom-dialog"', false)
            ->assertSee('محتاج أكتر أو حاجة مختلفة؟')
            ->assertSee('احكيلنا عن مركزك ونجهّز لك عرض يناسبك.')
            ->assertSee('ابعت طلبك')
            ->getContent();

        $this->assertSame(3, substr_count($html, 'class="pr-card pr-card-custom"'));
        foreach (['name', 'phone', 'email', 'org_name', 'audience', 'students_estimate', 'message', 'plan_slug'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html);
        }
        // The honeypot is off-screen, out of the tab order and hidden from screen readers.
        $start = strpos($html, '<dialog class="school-dialog" id="custom-dialog"');
        $dialog = substr($html, $start, strpos($html, '</dialog>', $start) - $start);
        $this->assertStringContainsString('<input type="text" name="website" class="su-trap" tabindex="-1" autocomplete="off" aria-hidden="true">', $dialog);
        foreach (['solo', 'group', 'center'] as $key) {
            $this->assertStringContainsString('<option value="'.$key.'">', $dialog);
        }
        // Without JS the custom card is a mailto link and shows the contact email.
        $this->assertStringContainsString('href="mailto:hello@mudarisy.test?subject=', $html);
        $this->assertStringContainsString('أو راسلنا على <a href="mailto:hello@mudarisy.test" dir="ltr">hello@mudarisy.test</a>', $html);
        $this->assertStringContainsString('data-custom-request data-source="card"', $html);
        $this->assertStringContainsString('data-custom-request data-source="calculator"', $html);
    }

    public function test_a_center_address_has_no_pricing_and_no_signup_dialog(): void
    {
        $this->get('http://demo.mudarisy.test/')->assertOk()
            ->assertDontSee('id="pricing"', false)
            ->assertDontSee('id="signup-dialog"', false)
            ->assertDontSee('landing/pricing.js', false)
            ->assertDontSee('باقة على مقاس مركزك');
    }

    public function test_a_center_address_has_no_calculator_and_no_custom_plan_form(): void
    {
        $this->get('http://demo.mudarisy.test/')->assertOk()
            ->assertDontSee('id="pr-calc"', false)
            ->assertDontSee('احسب باقتك بالإضافات')
            ->assertDontSee('id="custom-dialog"', false)
            ->assertDontSee('اطلب باقة مخصصة')
            ->assertDontSee('name="website"', false);
    }
}
