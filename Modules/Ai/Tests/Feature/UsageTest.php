<?php

declare(strict_types=1);

namespace Modules\Ai\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Modules\Ai\Models\DraftExam;
use Modules\Ai\Models\DraftQuestion;
use Modules\Ai\Models\UsageEntry;
use Modules\Ai\Tests\Concerns\BuildsBlueprints;
use Modules\Ai\Tests\Concerns\BuildsMaterials;
use Modules\Ai\Tests\Concerns\FakesExamAi;
use Modules\Auth\Models\User;
use Tests\TestCase;

class UsageTest extends TestCase
{
    use BuildsBlueprints, BuildsMaterials, FakesExamAi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableAi();
        Carbon::setTestNow('2026-10-20 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function entry(string $kind, int $units, ?User $user, ?DraftExam $draft, string $at): void
    {
        $time = Carbon::parse($at);
        UsageEntry::create(['kind' => $kind, 'user_id' => $user?->id, 'draft_id' => $draft?->id, 'units' => $units,
            'month' => $time->format('Y-m'), 'call_key' => 'seed:'.uniqid('', true), 'created_at' => $time]);
    }

    private function draftOf(User $owner, string $title): DraftExam
    {
        return DraftExam::create(['owner_user_id' => $owner->id, 'title' => $title, 'status' => 'draft', 'total_marks' => 10, 'duration_minutes' => 30]);
    }

    public function test_usage_returns_used_cap_breakdown_by_exam_and_a_daily_series(): void
    {
        config(['schools.plans.enterprise.limits.ai_units_monthly' => 50]);
        $a = User::factory()->create(['role' => 'teacher']);
        $b = User::factory()->create(['role' => 'teacher']);
        $examA = $this->draftOf($a, 'امتحان أ');
        $examB = $this->draftOf($b, 'امتحان ب');
        $this->entry('generate', 30, $a, $examA, '2026-10-05 10:00');
        $this->entry('verify', 5, $a, $examA, '2026-10-19 09:00');
        $this->entry('regenerate', 4, $b, $examB, '2026-10-20 08:00');
        $this->entry('generate', 100, $a, $examA, '2026-09-10 10:00'); // last month: neither counted nor charted

        $this->actAs('admin');
        $center = $this->getJson('/api/ai/usage')->assertOk()
            ->assertJsonPath('data.month', '2026-10')->assertJsonPath('data.cap_units', 50)
            ->assertJsonPath('data.used_units', 39)->assertJsonPath('data.remaining_units', 11)
            ->assertJsonPath('data.percent', 78)->assertJsonPath('data.warning', false)->assertJsonPath('data.blocked', false)
            ->assertJsonPath('data.message', null)->assertJsonPath('data.scope', 'center')
            ->assertJsonPath('data.breakdown', ['generate' => 30, 'regenerate' => 4, 'verify' => 5, 'grade' => 0])
            ->assertJsonPath('data.by_exam', [
                ['draft_id' => $examA->id, 'title' => 'امتحان أ', 'units' => 35],
                ['draft_id' => $examB->id, 'title' => 'امتحان ب', 'units' => 4],
            ])
            ->assertJsonCount(30, 'data.daily')
            ->assertJsonPath('data.daily.0', ['date' => '2026-09-21', 'units' => 0])
            ->assertJsonPath('data.daily.29', ['date' => '2026-10-20', 'units' => 4]);
        $this->assertSame(['date' => '2026-10-05', 'units' => 30], collect($center->json('data.daily'))->firstWhere('date', '2026-10-05'));
        $this->assertStringStartsWith('2026-11-01T00:00:00', $center->json('data.resets_at'));

        // A teacher sees the center's cap (it is shared) and only their own spending.
        Sanctum::actingAs($a);
        $this->getJson('/api/ai/usage')->assertOk()
            ->assertJsonPath('data.used_units', 39)->assertJsonPath('data.scope', 'mine')->assertJsonPath('data.mine_units', 35)
            ->assertJsonPath('data.breakdown', ['generate' => 30, 'regenerate' => 0, 'verify' => 5, 'grade' => 0])
            ->assertJsonCount(1, 'data.by_exam');
    }

    public function test_a_warning_at_80_percent_and_a_block_at_100_that_stops_new_jobs_and_regenerations(): void
    {
        config(['schools.plans.enterprise.limits.ai_units_monthly' => 50]);
        $teacher = $this->teacher();
        $draft = $this->generatedDraft($teacher); // spends 9 units (2 generate calls + 1 verify)
        $this->entry('generate', 31, $teacher, null, '2026-10-20 09:00'); // 40 of 50

        $this->getJson('/api/ai/usage')->assertOk()->assertJsonPath('data.percent', 80)->assertJsonPath('data.warning', true)
            ->assertJsonPath('data.blocked', false)->assertJsonPath('data.message', 'استهلك مركزك 80٪ من وحدات الذكاء الاصطناعي لهذا الشهر.');

        $this->entry('generate', 10, $teacher, null, '2026-10-20 10:00'); // 50 of 50
        $this->getJson('/api/ai/usage')->assertOk()->assertJsonPath('data.blocked', true)->assertJsonPath('data.remaining_units', 0)
            ->assertJsonPath('data.message', 'بلغ مركزك حد وحدات الذكاء الاصطناعي لهذا الشهر. يمكنك إنشاء الامتحانات يدوياً أو ترقية الباقة.');

        Queue::fake();
        $blueprint = $this->readyBlueprint($teacher);
        $this->postJson('/api/ai/generation-jobs/estimate', ['blueprint_id' => $blueprint->id])->assertOk()->assertJsonPath('data.can_run', false);
        $this->postJson('/api/ai/generation-jobs', ['blueprint_id' => $blueprint->id], ['Idempotency-Key' => 'blocked-key-01'])->assertStatus(402)->assertJsonPath('code', 'cap_reached');
        $q = DraftQuestion::query()->where('draft_id', $draft->id)->firstOrFail();
        $this->postJson("/api/ai/drafts/{$draft->id}/questions/{$q->id}/regenerate")->assertStatus(402)->assertJsonPath('code', 'cap_reached');
        // Reviewing and publishing by hand still work.
        $this->postJson("/api/ai/drafts/{$draft->id}/questions/{$q->id}/approve")->assertOk();
        Queue::assertNothingPushed();

        // A new month starts from zero.
        Carbon::setTestNow('2026-11-01 00:05:00');
        $this->getJson('/api/ai/usage')->assertOk()->assertJsonPath('data.month', '2026-11')
            ->assertJsonPath('data.used_units', 0)->assertJsonPath('data.blocked', false)->assertJsonPath('data.warning', false);
        $this->postJson('/api/ai/generation-jobs/estimate', ['blueprint_id' => $blueprint->id])->assertOk()->assertJsonPath('data.can_run', true);
    }

    public function test_a_plan_without_units_is_blocked_and_an_unlimited_one_never_is(): void
    {
        $this->teacher();
        config(['schools.plans.enterprise.limits.ai_units_monthly' => 0]);
        $this->getJson('/api/ai/usage')->assertOk()->assertJsonPath('data.cap_units', 0)->assertJsonPath('data.blocked', true);

        config(['schools.plans.enterprise.limits.ai_units_monthly' => null]);
        $this->getJson('/api/ai/usage')->assertOk()->assertJsonPath('data.cap_units', null)->assertJsonPath('data.remaining_units', null)
            ->assertJsonPath('data.percent', null)->assertJsonPath('data.blocked', false);
    }

    public function test_usage_is_for_the_centers_staff_on_a_plan_with_the_feature(): void
    {
        $this->actAs('student');
        $this->getJson('/api/ai/usage')->assertForbidden();
        $this->actAs('parent');
        $this->getJson('/api/ai/usage')->assertForbidden();

        $this->teacher();
        config(['schools.plans.enterprise.features.ai_exams' => 'soon']);
        $this->getJson('/api/ai/usage')->assertForbidden()->assertJsonPath('code', 'plan_feature')->assertJsonPath('soon', true);
    }

    public function test_the_flag_flips_only_for_the_tiers_named_and_never_for_a_closed_one(): void
    {
        $plans = $this->plansWith('t3, t4,t1');

        $this->assertTrue($plans['t3']['features']['ai_exams']);
        $this->assertTrue($plans['t4']['features']['ai_exams']);
        $this->assertSame('soon', $plans['t5']['features']['ai_exams']);
        $this->assertFalse($plans['t1']['features']['ai_exams']); // false stays false
        $this->assertSame('soon', $this->plansWith('')['t3']['features']['ai_exams']);
    }

    /** @return array<string, array> the plans config as it reads with AI_EXAMS_LIVE_TIERS set */
    private function plansWith(string $tiers): array
    {
        $before = getenv('AI_EXAMS_LIVE_TIERS');
        putenv("AI_EXAMS_LIVE_TIERS={$tiers}");
        $_ENV['AI_EXAMS_LIVE_TIERS'] = $_SERVER['AI_EXAMS_LIVE_TIERS'] = $tiers;
        try {
            return (require config_path('schools.php'))['plans'];
        } finally {
            $before === false ? putenv('AI_EXAMS_LIVE_TIERS') : putenv("AI_EXAMS_LIVE_TIERS={$before}");
            unset($_ENV['AI_EXAMS_LIVE_TIERS'], $_SERVER['AI_EXAMS_LIVE_TIERS']);
        }
    }
}
