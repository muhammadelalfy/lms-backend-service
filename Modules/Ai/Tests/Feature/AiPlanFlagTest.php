<?php

declare(strict_types=1);

namespace Modules\Ai\Tests\Feature;

use Modules\Tenancy\Models\School;
use Modules\Tenancy\Services\PlanCatalog;
use Tests\TestCase;

class AiPlanFlagTest extends TestCase
{
    public function test_the_catalog_carries_the_flag_and_the_caps_per_plan(): void
    {
        $plans = collect((new PlanCatalog)->publicPlans());

        $this->assertFalse($plans['t1']['features']['ai_exams']);
        $this->assertFalse($plans['t2']['features']['ai_exams']);
        foreach (['t3', 't4', 't5', 't6', 't7', 't8'] as $slug) {
            $this->assertSame('soon', $plans[$slug]['features']['ai_exams'], $slug);
        }
        $this->assertSame([300, 500, 50], array_values(array_intersect_key($plans['t3']['limits'], array_flip(['ai_units_monthly', 'ai_material_storage_mb', 'ai_materials_max']))));
        $this->assertSame(0, $plans['t1']['limits']['ai_units_monthly']);
        $this->assertSame(10240, $plans['t7']['limits']['ai_material_storage_mb']);
    }

    public function test_the_public_plans_endpoint_shows_the_flag_and_a_comparison_row(): void
    {
        $response = $this->getJson('http://localhost/api/plans')->assertOk();

        $this->assertSame('soon', collect($response->json('plans'))->firstWhere('slug', 't4')['features']['ai_exams']);
        $keys = collect($response->json('feature_rows'))->flatMap(fn ($g) => collect($g['rows'])->pluck('key'));
        $this->assertTrue($keys->contains('ai_exams'));
    }

    public function test_a_school_resolves_its_flag_from_the_plan_unless_the_operator_overrides_it(): void
    {
        $school = School::findOrFail('demo');
        $school->plan = 't4';
        $this->assertSame('soon', $school->feature('ai_exams'));
        $school->plan = 't1';
        $this->assertFalse($school->feature('ai_exams'));
        $this->assertFalse($school->feature('nonexistent'));
        $this->assertSame(0, $school->limit('ai_materials_max'));
        $school->features = ['ai_exams' => true];
        $this->assertTrue($school->feature('ai_exams'));
    }
}
