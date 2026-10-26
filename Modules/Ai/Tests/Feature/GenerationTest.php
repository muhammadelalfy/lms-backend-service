<?php

declare(strict_types=1);

namespace Modules\Ai\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Modules\Ai\Actions\RunGeneration;
use Modules\Ai\Enums\JobStatus;
use Modules\Ai\Enums\UsageKind;
use Modules\Ai\Events\GenerationCompleted;
use Modules\Ai\Events\GenerationRequested;
use Modules\Ai\Exceptions\AiException;
use Modules\Ai\Jobs\RunGenerationJob;
use Modules\Ai\Models\DraftExam;
use Modules\Ai\Models\DraftQuestion;
use Modules\Ai\Models\GenerationJob;
use Modules\Ai\Models\UsageEntry;
use Modules\Ai\Services\UnitBudget;
use Modules\Ai\Tests\Concerns\BuildsBlueprints;
use Modules\Ai\Tests\Concerns\BuildsMaterials;
use Modules\Ai\Tests\Concerns\FakesExamAi;
use Modules\Auth\Models\User;
use Tests\TestCase;

class GenerationTest extends TestCase
{
    use BuildsBlueprints, BuildsMaterials, FakesExamAi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableAi();
    }

    private function cap(?int $units): void
    {
        config(['schools.plans.enterprise.limits.ai_units_monthly' => $units]);
    }

    private function spend(int $units, ?User $user = null): void
    {
        UsageEntry::create(['kind' => 'generate', 'user_id' => $user?->id, 'units' => $units, 'month' => now()->format('Y-m'), 'call_key' => 'seed:'.uniqid(), 'created_at' => now()]);
    }

    private function start(int $blueprintId, string $key = 'gen-key-00001')
    {
        return $this->postJson('/api/ai/generation-jobs', ['blueprint_id' => $blueprintId], ['Idempotency-Key' => $key]);
    }

    public function test_the_estimate_shows_units_and_what_is_left_of_the_month(): void
    {
        $this->fakeExamAi();
        $this->cap(100);
        $blueprint = $this->readyBlueprint();
        $this->spend(30);

        $this->postJson('/api/ai/generation-jobs/estimate', ['blueprint_id' => $blueprint->id])->assertOk()
            ->assertJsonPath('data.units', 10)->assertJsonPath('data.breakdown', ['generate' => 8, 'verify' => 2])
            ->assertJsonPath('data.requested_questions', 12)->assertJsonPath('data.cap_units', 100)
            ->assertJsonPath('data.used_units', 30)->assertJsonPath('data.remaining_units', 70)
            ->assertJsonPath('data.can_run', true)->assertJsonPath('data.reason', null);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/exams/estimate')
            && $r->hasHeader('X-Center-Id', 'demo') && $r->hasHeader('Authorization', 'Bearer service-token')
            && $r['material_ids'] === [(string) $blueprint->source_selection[0]['material_id']] && $r['section_ids'] === null
            && $r['blueprint']['types'] === [['type' => 'mcq', 'count' => 10, 'marks' => 1], ['type' => 'essay', 'count' => 2, 'marks' => 5]]
            && $r['blueprint']['total_marks'] === 20);

        $this->spend(65);
        $this->postJson('/api/ai/generation-jobs/estimate', ['blueprint_id' => $blueprint->id])->assertOk()
            ->assertJsonPath('data.remaining_units', 5)->assertJsonPath('data.can_run', false)->assertJsonPath('data.reason', 'cap_reached');
    }

    public function test_an_inline_blueprint_can_be_estimated_before_it_is_saved(): void
    {
        $this->fakeExamAi();
        $teacher = $this->teacher();
        $material = $this->material($teacher, ['status' => 'ready']);
        $excluded = $material->sections()->create(['kind' => 'page', 'label' => 'صفحة 1', 'ordinal' => 1, 'excluded' => true]);
        $kept = $material->sections()->create(['kind' => 'page', 'label' => 'صفحة 2', 'ordinal' => 2]);

        $this->postJson('/api/ai/generation-jobs/estimate', $this->blueprintPayload(['sources' => [['material_id' => $material->id]]]))
            ->assertOk()->assertJsonPath('data.can_run', true)->assertJsonPath('data.remaining_units', null);
        // The teacher's excluded pages are never used.
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/estimate') && $r['section_ids'] === [$kept->id]);
        $this->assertNotSame($kept->id, $excluded->id);

        $this->postJson('/api/ai/generation-jobs/estimate', $this->blueprintPayload())->assertStatus(422)->assertJsonValidationErrors(['sources']);
        $this->postJson('/api/ai/generation-jobs/estimate', $this->blueprintPayload(['total_marks' => 99, 'sources' => [['material_id' => $material->id]]]))
            ->assertStatus(422)->assertJsonValidationErrors(['total_marks']);
    }

    public function test_no_ready_material_and_a_down_ai_service_are_explained(): void
    {
        $teacher = $this->teacher();
        $waiting = $this->material($teacher, ['status' => 'extracting']);
        $blueprint = $this->blueprint($teacher, ['source_selection' => [['material_id' => $waiting->id, 'section_ids' => null]]]);
        $this->fakeExamAi();

        $this->postJson('/api/ai/generation-jobs/estimate', ['blueprint_id' => $blueprint->id])->assertStatus(422)->assertJsonPath('code', 'no_material');
        $this->start($blueprint->id)->assertStatus(422)->assertJsonPath('code', 'no_material');

        $ready = $this->readyBlueprint($teacher);
        $this->fakeExamAi(['ai.test/v1/exams/estimate' => Http::response(['message' => 'down'], 503)]);
        $this->postJson('/api/ai/generation-jobs/estimate', ['blueprint_id' => $ready->id])->assertStatus(503)
            ->assertJsonPath('code', 'ai_unavailable')->assertJsonPath('message', 'المساعد غير متاح الآن. أعد المحاولة بعد قليل.');
        $this->start($ready->id)->assertStatus(503);
        $this->assertSame(0, GenerationJob::count());
        $this->assertSame(0, DraftExam::count());
    }

    public function test_the_same_idempotency_key_starts_one_job_even_when_the_response_cache_forgot_it(): void
    {
        Queue::fake();
        Event::fake([GenerationRequested::class]);
        $this->fakeExamAi();
        $blueprint = $this->readyBlueprint();

        $a = $this->start($blueprint->id)->assertStatus(202)->assertJsonPath('data.status', 'queued');
        $b = $this->start($blueprint->id)->assertStatus(202);
        Cache::flush();
        $c = $this->start($blueprint->id)->assertStatus(202);

        $this->assertSame($a->json('data.id'), $b->json('data.id'));
        $this->assertSame($a->json('data.id'), $c->json('data.id'));
        $this->assertSame(1, GenerationJob::count());
        $this->assertSame(1, DraftExam::count());
        Queue::assertPushedOn('ai', RunGenerationJob::class);
        Queue::assertPushed(RunGenerationJob::class, 1);
        Event::assertDispatchedTimes(GenerationRequested::class, 1);

        $this->start($blueprint->id, 'gen-key-00002')->assertStatus(202);
        $this->assertSame(2, GenerationJob::count());
    }

    public function test_a_job_runs_through_its_states_in_batches_writes_the_ledger_and_saves_questions_with_provenance(): void
    {
        $seen = [];
        $this->aiWeakPositions = [1];
        $this->fakeExamAi();
        $record = function (string $path) use (&$seen) {
            return function (Request $r) use (&$seen, $path) {
                $seen[] = GenerationJob::query()->toBase()->value('status');

                return Http::response($path === 'g' ? $this->generated($r) : $this->verified($r));
            };
        };
        $this->fakeExamAi(['ai.test/v1/exams/generate' => $record('g'), 'ai.test/v1/exams/verify' => $record('v')]);
        Event::fake([GenerationCompleted::class]);
        $this->cap(100);
        $teacher = $this->teacher();
        $blueprint = $this->readyBlueprint($teacher);

        $id = $this->start($blueprint->id)->assertStatus(202)->json('data.id');

        $this->assertSame(['generating', 'generating', 'verifying'], $seen);
        $job = $this->getJson("/api/ai/generation-jobs/{$id}")->assertOk()
            ->assertJsonPath('data.status', 'ready')->assertJsonPath('data.partial', false)
            ->assertJsonPath('data.progress.batches_total', 2)->assertJsonPath('data.progress.batches_done', 2)
            ->assertJsonPath('data.progress.questions_generated', 12)->assertJsonPath('data.progress.questions_verified', 12)
            ->assertJsonPath('data.progress.percent', 100)->assertJsonPath('data.units_used', 9)
            ->assertJsonPath('data.reserved_units', 0)->assertJsonPath('data.notice', null)
            ->assertJsonPath('data.model_generate', 'claude-strong')->assertJsonPath('data.model_verify', 'claude-cheap')
            ->assertJsonPath('data.prompt_versions', ['verify' => 'verify.v1', 'generate' => 'generate.v1']);

        // Batches of at most 10 questions, each within the reservation, never any material text.
        $generateCalls = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/v1/exams/generate'))->values();
        $this->assertCount(2, $generateCalls);
        [$first] = $generateCalls[0];
        $this->assertSame([['type' => 'mcq', 'count' => 10, 'marks' => 1]], $first['blueprint']['types']);
        $this->assertNull($first['blueprint']['total_marks']);
        $this->assertSame(12, $first['limit_units']);
        $this->assertSame("j{$id}b0", $first['variation_key']);
        $this->assertSame([['type' => 'essay', 'count' => 2, 'marks' => 5]], $generateCalls[1][0]['blueprint']['types']);
        $this->assertSame(8, $generateCalls[1][0]['limit_units']);

        // Ledger: one row per AI call, owned by the requester.
        $this->assertSame(['generate' => 8, 'verify' => 1], UsageEntry::query()->where('job_id', $id)->get()
            ->groupBy(fn ($e) => $e->kind->value)->map->sum('units')->all());
        $this->assertSame([$teacher->id], UsageEntry::query()->where('job_id', $id)->distinct()->pluck('user_id')->all());

        $draftId = $job->json('data.draft_id');
        $draft = $this->getJson("/api/ai/drafts/{$draftId}?flagged_first=1")->assertOk()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.total_marks', 20)
            ->assertJsonPath('data.counts', ['questions' => 12, 'approved' => 0, 'pending' => 12, 'flagged' => 1])
            ->assertJsonPath('data.marks_sum', 20)->assertJsonPath('data.can_publish', false)
            ->assertJsonPath('data.blockers.0.code', 'not_all_approved');
        $flagged = $draft->json('data.questions.0');
        $this->assertTrue($flagged['flagged']);
        $this->assertSame('unsupported', $flagged['verification']['verdict']);
        $this->assertSame(0.4, $flagged['verification']['confidence']);
        $this->assertSame(['الإجابة لا يدعمها المصدر'], $flagged['verification']['issues']);
        $this->assertFalse($flagged['auto_approvable']);
        $q = $draft->json('data.questions.1');
        $this->assertSame('mcq', $q['type']);
        $this->assertSame(['material_id' => $blueprint->source_selection[0]['material_id'], 'chunk_ref' => 'c_1', 'label' => 'صفحة 3', 'quote' => 'تصنع النباتات غذاءها', 'ocr' => false], $q['citation']);
        $this->assertSame('ب1', $q['correct_answer']);
        $this->assertSame('generate.v1', $q['prompt_version']);
        $this->assertSame('claude-strong', $q['model_id']);
        $essay = DraftQuestion::query()->where('type', 'essay')->firstOrFail();
        $this->assertEquals(['criteria' => [['id' => 'c1', 'text' => 'يذكر الفكرة', 'marks' => 4], ['id' => 'c2', 'text' => 'يشرح', 'marks' => 1]], 'accept_alternatives' => ['بديل']], $essay->rubric);
        $this->assertSame('إجابة نموذجية 11', $essay->model_answer);
        $this->assertSame(5, $essay->marks);

        Event::assertDispatched(GenerationCompleted::class, fn ($e) => $e->jobId === $id && $e->status === 'ready');
        $this->assertSame(['cap' => 100, 'used' => 9, 'reserved' => 0, 'remaining' => 91], array_intersect_key(app(UnitBudget::class)->snapshot(), array_flip(['cap', 'used', 'reserved', 'remaining'])));
    }

    public function test_an_under_filled_job_keeps_what_is_grounded_and_says_so(): void
    {
        $this->aiMaxPerCall = 6;
        $this->fakeExamAi();
        $blueprint = $this->readyBlueprint();

        $id = $this->start($blueprint->id)->assertStatus(202)->json('data.id');

        $this->getJson("/api/ai/generation-jobs/{$id}")->assertOk()
            ->assertJsonPath('data.status', 'needs_attention')->assertJsonPath('data.partial', true)
            ->assertJsonPath('data.progress.questions_generated', 8)
            ->assertJsonPath('data.notice', 'تم توليد 8 من 12: المحتوى لا يكفي')
            ->assertJsonPath('data.shortfall.0', ['type' => 'mcq', 'requested' => 10, 'produced' => 6, 'reason' => 'insufficient_content'])
            ->assertJsonPath('data.can_retry', false);
        $this->assertSame(8, DraftQuestion::count());
    }

    public function test_the_ai_going_down_mid_job_is_retried_then_fails_keeping_the_partial_draft_and_charging_nothing_for_failed_calls(): void
    {
        Queue::fake();
        $calls = 0;
        $this->fakeExamAi();
        $this->fakeExamAi(['ai.test/v1/exams/generate' => function (Request $r) use (&$calls) {
            return ++$calls === 1 ? Http::response($this->generated($r)) : Http::response(['message' => 'busy'], 503);
        }]);
        $blueprint = $this->readyBlueprint();
        $id = $this->start($blueprint->id)->assertStatus(202)->json('data.id');

        try {
            app(RunGeneration::class)->handle($id, final: false);
            $this->fail('A transient failure must be retried by the queue.');
        } catch (AiException $e) {
            $this->assertTrue($e->transient);
        }
        $this->assertSame(JobStatus::Generating, GenerationJob::findOrFail($id)->status);
        app(RunGeneration::class)->handle($id, final: true);

        $this->getJson("/api/ai/generation-jobs/{$id}")->assertOk()
            ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error_code', 'ai_unavailable')
            ->assertJsonPath('data.error_message', 'المساعد غير متاح الآن. أعد المحاولة بعد قليل.')
            ->assertJsonPath('data.partial', true)->assertJsonPath('data.reserved_units', 0)
            ->assertJsonPath('data.progress.batches_done', 1)->assertJsonPath('data.can_retry', true);
        $this->assertSame(3, $calls); // batch 1 once; batch 2 twice, never batch 1 again
        $this->assertSame(10, DraftQuestion::count());
        $this->assertSame(1, UsageEntry::count());
        $this->assertSame(4, (int) UsageEntry::sum('units'));

        // The retry button resumes after the saved batch.
        $this->fakeExamAi();
        $this->postJson("/api/ai/generation-jobs/{$id}/retry")->assertStatus(202)->assertJsonPath('data.status', 'queued');
        app(RunGeneration::class)->handle($id);
        $this->assertSame('ready', GenerationJob::findOrFail($id)->status->value);
        $this->assertSame(12, DraftQuestion::count());
        $this->assertSame(['generate' => 2, 'verify' => 1], UsageEntry::query()->get()->groupBy(fn ($e) => $e->kind->value)->map->count()->all());
        $this->postJson("/api/ai/generation-jobs/{$id}/retry")->assertStatus(409)->assertJsonPath('code', 'not_retryable');
    }

    public function test_a_job_over_the_monthly_cap_is_refused_and_the_manual_builder_still_works(): void
    {
        Queue::fake();
        $this->fakeExamAi();
        $this->cap(30);
        $this->spend(25);
        $blueprint = $this->readyBlueprint();

        $this->start($blueprint->id)->assertStatus(402)->assertJsonPath('code', 'cap_reached')->assertJsonPath('cap', 'ai_units_monthly');
        $this->assertSame(0, GenerationJob::count());
        $this->assertSame(0, DraftExam::count());

        $this->postJson('/api/exam-templates', ['title' => 'يدوي', 'duration_minutes' => 30, 'questions' => [
            ['type' => 'mcq', 'prompt_html' => '<p>سؤال</p>', 'options' => ['أ', 'ب'], 'correct_answer' => 'أ', 'points' => 1],
        ]])->assertCreated();
    }

    public function test_two_jobs_cannot_both_take_the_last_units(): void
    {
        Queue::fake();
        $this->fakeExamAi();
        $this->cap(15);
        $teacher = $this->teacher();
        $one = $this->readyBlueprint($teacher);
        $two = $this->readyBlueprint($teacher);

        $this->start($one->id, 'first-key-0001')->assertStatus(202)->assertJsonPath('data.reserved_units', 12);
        $this->start($two->id, 'second-key-001')->assertStatus(402)->assertJsonPath('code', 'cap_reached');
        $this->postJson('/api/ai/generation-jobs/estimate', ['blueprint_id' => $two->id])
            ->assertJsonPath('data.reserved_units', 12)->assertJsonPath('data.remaining_units', 3)->assertJsonPath('data.can_run', false);
    }

    public function test_a_running_job_stops_at_its_reservation_and_keeps_a_partial_draft(): void
    {
        $this->aiGenerateUnits = 8;
        $this->fakeExamAi();
        $this->cap(12);
        $blueprint = $this->readyBlueprint();

        $id = $this->start($blueprint->id)->assertStatus(202)->json('data.id');

        $job = GenerationJob::findOrFail($id);
        $this->assertSame(JobStatus::NeedsAttention, $job->status);
        $this->assertSame('cap_reached', $job->error_code);
        $this->assertSame(12, $job->units_used);
        $this->assertSame(12, (int) UsageEntry::sum('units')); // never past the cap
        $this->assertSame(11, $job->questions_generated); // batch 1 whole, batch 2 cut to 1 essay by limit_units
        // No unit left for verification: every question is flagged for the teacher.
        $this->assertSame(11, DraftQuestion::where('flagged', true)->whereNull('verify_verdict')->count());
        $this->getJson("/api/ai/generation-jobs/{$id}")->assertJsonPath('data.notice', 'تم توليد 11 من 12: انتهت وحدات الشهر')
            ->assertJsonPath('data.can_retry', true);
        $this->start($blueprint->id, 'another-key-01')->assertStatus(402);
    }

    public function test_charging_the_same_call_twice_counts_once(): void
    {
        Queue::fake();
        $this->fakeExamAi();
        $blueprint = $this->readyBlueprint();
        $job = GenerationJob::findOrFail($this->start($blueprint->id)->json('data.id'));
        $budget = app(UnitBudget::class);

        $this->assertTrue($budget->charge($job, UsageKind::Generate, 'job:x:generate:0', 5, 'm', 'generate.v1'));
        $this->assertFalse($budget->charge($job, UsageKind::Generate, 'job:x:generate:0', 5, 'm', 'generate.v1'));

        $this->assertSame(5, (int) UsageEntry::sum('units'));
        $this->assertSame(5, $job->refresh()->units_used);
        $this->assertSame(7, $job->reserved_units);
    }

    public function test_a_cancelled_job_stops_gives_back_its_units_and_never_calls_the_ai(): void
    {
        Queue::fake();
        $this->fakeExamAi();
        $this->cap(100);
        $blueprint = $this->readyBlueprint();
        $id = $this->start($blueprint->id)->json('data.id');

        $this->postJson("/api/ai/generation-jobs/{$id}/cancel")->assertOk()
            ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.reserved_units', 0)->assertJsonPath('data.can_cancel', false);
        app(RunGeneration::class)->handle($id);

        $this->assertSame(0, $this->aiCalls('/v1/exams/generate'));
        $this->postJson("/api/ai/generation-jobs/{$id}/cancel")->assertStatus(409);
        $this->assertSame(100, app(UnitBudget::class)->snapshot()['remaining']);
    }

    public function test_the_blueprint_snapshot_inside_a_job_never_changes(): void
    {
        Queue::fake();
        $this->fakeExamAi();
        $blueprint = $this->readyBlueprint();
        $id = $this->start($blueprint->id)->json('data.id');

        $this->putJson("/api/ai/blueprints/{$blueprint->id}", ['title' => 'عنوان آخر', 'types' => [['type' => 'mcq', 'count' => 2, 'marks' => 10]]])->assertOk();

        $snapshot = GenerationJob::findOrFail($id)->blueprint_snapshot;
        $this->assertSame('امتحان العلوم', $snapshot['title']);
        $this->assertSame(10, $snapshot['types'][0]['count']);
        $this->assertSame('امتحان العلوم', DraftExam::firstOrFail()->title);
    }

    public function test_only_the_owner_or_the_admin_sees_a_job_and_its_draft(): void
    {
        $this->fakeExamAi();
        $owner = $this->teacher();
        $blueprint = $this->readyBlueprint($owner);
        $id = $this->start($blueprint->id)->json('data.id');
        $draftId = GenerationJob::findOrFail($id)->draft_id;

        $this->actAs('teacher');
        $this->getJson("/api/ai/generation-jobs/{$id}")->assertNotFound();
        $this->postJson("/api/ai/generation-jobs/{$id}/cancel")->assertNotFound();
        $this->getJson("/api/ai/drafts/{$draftId}")->assertNotFound();
        $this->getJson('/api/ai/drafts')->assertOk()->assertJsonCount(0, 'data');
        // Someone else's blueprint cannot be estimated or run.
        $this->postJson('/api/ai/generation-jobs/estimate', ['blueprint_id' => $blueprint->id])->assertNotFound();
        $this->start($blueprint->id, 'other-key-0001')->assertNotFound();

        $this->actAs('admin');
        $this->getJson("/api/ai/generation-jobs/{$id}")->assertOk();
        $this->getJson("/api/ai/drafts/{$draftId}")->assertOk();
        $this->getJson('/api/ai/drafts')->assertOk()->assertJsonCount(1, 'data');

        foreach (['student', 'parent'] as $role) {
            $this->actAs($role);
            $this->getJson("/api/ai/generation-jobs/{$id}")->assertForbidden();
            $this->getJson("/api/ai/drafts/{$draftId}")->assertForbidden();
            $this->start($blueprint->id, "{$role}-key-0001")->assertForbidden();
        }

        Sanctum::actingAs($owner);
        $this->getJson('/api/ai/drafts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $draftId);
        config(['schools.plans.enterprise.features.ai_exams' => false]);
        $this->start($blueprint->id, 'late-key-00001')->assertForbidden()->assertJsonPath('code', 'plan_feature');
    }

    public function test_another_center_never_sees_a_job_or_a_draft(): void
    {
        $this->fakeExamAi();
        $blueprint = $this->readyBlueprint();
        $id = $this->start($blueprint->id)->json('data.id');
        $draftId = GenerationJob::findOrFail($id)->draft_id;

        $this->openSchool('beta');
        config(['schools.plans.enterprise.features.ai_exams' => true]);
        URL::forceRootUrl('http://beta.mudarisy.test');
        $this->app['auth']->forgetGuards();
        $this->actAs('admin');

        $this->getJson("/api/ai/generation-jobs/{$id}")->assertNotFound();
        $this->getJson("/api/ai/drafts/{$draftId}")->assertNotFound();
        $this->getJson('/api/ai/drafts')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(0, (int) UsageEntry::sum('units'));
    }
}
