<?php

declare(strict_types=1);

namespace Modules\Ai\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Modules\Ai\Models\DraftEvent;
use Modules\Ai\Models\DraftQuestion;
use Modules\Ai\Models\GenerationJob;
use Modules\Ai\Models\UsageEntry;
use Modules\Ai\Tests\Concerns\BuildsBlueprints;
use Modules\Ai\Tests\Concerns\BuildsMaterials;
use Modules\Ai\Tests\Concerns\FakesExamAi;
use Tests\TestCase;

class DraftReviewTest extends TestCase
{
    use BuildsBlueprints, BuildsMaterials, FakesExamAi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableAi();
    }

    private function mcq(int $draftId): DraftQuestion
    {
        return DraftQuestion::query()->where('draft_id', $draftId)->where('type', 'mcq')->orderBy('ordinal')->firstOrFail();
    }

    public function test_a_teacher_edits_any_field_and_the_edit_is_audited(): void
    {
        $teacher = $this->teacher();
        $draft = $this->generatedDraft($teacher);
        $q = $this->mcq($draft->id);
        $url = "/api/ai/drafts/{$draft->id}/questions/{$q->id}";

        $this->putJson($url, ['prompt' => 'ما ناتج التمثيل الضوئي؟', 'options' => ['أكسجين', 'نيتروجين', 'هيليوم'], 'correct_answer' => 'أكسجين', 'explanation' => 'لأن...', 'difficulty' => 'hard'])
            ->assertOk()->assertJsonPath('data.questions.0.prompt', 'ما ناتج التمثيل الضوئي؟')
            ->assertJsonPath('data.questions.0.options', ['أكسجين', 'نيتروجين', 'هيليوم'])
            ->assertJsonPath('data.questions.0.edited_by_teacher', true)
            ->assertJsonPath('data.questions.0.state', 'pending');
        $event = DraftEvent::query()->where('action', 'edited')->firstOrFail();
        $this->assertSame($teacher->id, $event->actor_id);
        $this->assertSame($q->id, $event->question_id);
        $this->assertEqualsCanonicalizing(['prompt', 'options', 'correct_answer', 'explanation', 'difficulty'], $event->details['fields']);

        $this->putJson($url, ['correct_answer' => 'ذهب'])->assertStatus(422)->assertJsonValidationErrors(['correct_answer']);
        $this->putJson($url, ['options' => ['واحد']])->assertStatus(422)->assertJsonValidationErrors(['options']);
        $this->putJson($url, ['difficulty' => 'impossible'])->assertStatus(422)->assertJsonValidationErrors(['difficulty']);

        $essay = DraftQuestion::query()->where('draft_id', $draft->id)->where('type', 'essay')->firstOrFail();
        $this->putJson("/api/ai/drafts/{$draft->id}/questions/{$essay->id}", ['rubric' => ['criteria' => [['id' => 'c1', 'text' => 'كامل', 'marks' => 3]]]])
            ->assertStatus(422)->assertJsonValidationErrors(['rubric' => 'مجموع درجات بنود التصحيح (3) لا يساوي درجة السؤال (5).']);
        $this->putJson("/api/ai/drafts/{$draft->id}/questions/{$essay->id}", ['marks' => 3, 'rubric' => ['criteria' => [['id' => 'c1', 'text' => 'كامل', 'marks' => 3]]], 'model_answer' => 'جواب'])
            ->assertOk();
        $this->assertSame(3, $essay->refresh()->marks);

        // An MCQ turned into a written question drops its options.
        $this->putJson($url, ['type' => 'short_answer', 'model_answer' => 'الأكسجين'])->assertOk();
        $this->assertNull($q->refresh()->options);
    }

    public function test_approve_unapprove_remove_and_approve_all_unflagged(): void
    {
        $this->aiWeakPositions = [0, 3];
        $this->teacher();
        $draft = $this->generatedDraft();
        $questions = DraftQuestion::query()->where('draft_id', $draft->id)->orderBy('id')->get();
        $this->assertSame(2, $questions->where('flagged', true)->count());
        $base = "/api/ai/drafts/{$draft->id}";

        $this->postJson("{$base}/questions", ['type' => 'true_false', 'prompt' => 'سؤال يدوي', 'correct_answer' => 'صواب', 'marks' => 1])
            ->assertCreated()->assertJsonPath('data.counts.questions', 13)->assertJsonPath('data.questions.12.origin', 'manual')
            ->assertJsonPath('data.questions.12.options', ['صواب', 'خطأ'])->assertJsonPath('data.questions.12.auto_approvable', true);

        $all = $this->postJson("{$base}/approve-all-unflagged")->assertOk()
            ->assertJsonPath('approved_count', 11)->assertJsonPath('data.counts.approved', 11)->assertJsonPath('data.counts.pending', 2);
        $this->assertSame(['not_all_approved', 'marks_mismatch'], array_column($all->json('data.blockers'), 'code'));

        $flagged = $questions->where('flagged', true)->values();
        $this->postJson("{$base}/questions/{$flagged[0]->id}/approve")->assertOk()->assertJsonPath('data.counts.approved', 12);
        $this->postJson("{$base}/questions/{$flagged[0]->id}/approve")->assertOk(); // the same decision twice
        $this->postJson("{$base}/questions/{$flagged[0]->id}/unapprove")->assertOk()->assertJsonPath('data.counts.approved', 11);
        $this->deleteJson("{$base}/questions/{$flagged[1]->id}")->assertOk()->assertJsonPath('data.counts.questions', 12);
        $this->postJson("{$base}/questions/{$flagged[1]->id}/approve")->assertStatus(409)->assertJsonPath('code', 'question_not_live');

        $this->getJson("{$base}?history=1")->assertOk()->assertJsonCount(13, 'data.questions');
        $this->assertSame(
            ['added', 'approved_all_unflagged', 'approved', 'unapproved', 'removed'],
            DraftEvent::query()->orderBy('id')->pluck('action')->all(),
        );
        $this->getJson("{$base}/events")->assertOk()->assertJsonPath('data.0.action', 'removed')->assertJsonCount(5, 'data');
    }

    public function test_reordering_takes_every_live_question_exactly_once(): void
    {
        $this->teacher();
        $draft = $this->generatedDraft();
        $ids = DraftQuestion::query()->where('draft_id', $draft->id)->orderBy('ordinal')->pluck('id')->all();
        $reversed = array_reverse($ids);

        $this->postJson("/api/ai/drafts/{$draft->id}/reorder", ['question_ids' => $reversed])->assertOk()
            ->assertJsonPath('data.questions.0.id', $reversed[0])->assertJsonPath('data.questions.11.id', $ids[0]);
        $this->postJson("/api/ai/drafts/{$draft->id}/reorder", ['question_ids' => array_slice($ids, 1)])->assertStatus(422)->assertJsonPath('code', 'invalid_order');
    }

    public function test_regenerate_one_replaces_the_question_charges_the_ledger_and_keeps_the_original(): void
    {
        $this->teacher();
        $draft = $this->generatedDraft();
        $q = $this->mcq($draft->id);
        $url = "/api/ai/drafts/{$draft->id}/questions/{$q->id}/regenerate";
        $headers = ['Idempotency-Key' => 'regen-key-0001'];

        $jobId = $this->postJson($url, ['instruction' => 'اجعله أصعب'], $headers)->assertStatus(202)
            ->assertJsonPath('data.kind', 'regenerate')->assertJsonPath('data.target_question_id', $q->id)->json('data.id');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/exams/generate') && ($r['replace_ref'] ?? null) === "d{$q->id}"
            && $r['instruction'] === 'اجعله أصعب' && $r['blueprint']['types'] === [['type' => 'mcq', 'count' => 1, 'marks' => 1]]
            && $r['variation_key'] === "r{$jobId}" && $r['blueprint']['outcomes'] === [['id' => 'O1', 'text' => 'يتعرف التمثيل الضوئي']]);
        $this->assertSame('ready', GenerationJob::findOrFail($jobId)->status->value);
        $q->refresh();
        $this->assertSame('replaced', $q->state->value);
        $replacement = DraftQuestion::query()->findOrFail($q->replaced_by_id);
        $this->assertSame('regenerated', $replacement->origin);
        $this->assertSame($q->id, $replacement->replaces_id);
        $this->assertSame($q->ordinal, $replacement->ordinal);
        $this->assertSame('supported', $replacement->verify_verdict);
        $this->assertSame(1, UsageEntry::query()->where('kind', 'regenerate')->where('job_id', $jobId)->count());
        $this->assertSame(1, UsageEntry::query()->where('kind', 'verify')->where('job_id', $jobId)->count());
        $this->getJson("/api/ai/drafts/{$draft->id}")->assertJsonPath('data.counts.questions', 12)
            ->assertJsonPath('data.questions.0.id', $replacement->id);
        $this->assertTrue(DraftEvent::query()->where('action', 'regenerated')->where('question_id', $q->id)->exists());

        // Same key: the same job, no second call. The replaced question cannot be regenerated again.
        $this->postJson($url, ['instruction' => 'اجعله أصعب'], $headers)->assertStatus(202)->assertJsonPath('data.id', $jobId);
        $this->postJson($url, [], ['Idempotency-Key' => 'regen-key-0002'])->assertStatus(409)->assertJsonPath('code', 'question_not_live');
        $this->assertSame(1, GenerationJob::query()->where('kind', 'regenerate')->count());
    }

    public function test_regenerate_refuses_when_the_ai_is_down_the_cap_is_reached_or_one_is_already_running(): void
    {
        $this->teacher();
        $draft = $this->generatedDraft();
        $q = $this->mcq($draft->id);
        $url = "/api/ai/drafts/{$draft->id}/questions/{$q->id}/regenerate";

        $this->fakeExamAi(['ai.test/v1/exams/estimate' => Http::response(['message' => 'down'], 503)]);
        $this->postJson($url)->assertStatus(503)->assertJsonPath('code', 'ai_unavailable');
        $this->assertSame('pending', $q->refresh()->state->value); // stays editable by hand

        $this->fakeExamAi();
        config(['schools.plans.enterprise.limits.ai_units_monthly' => (int) UsageEntry::sum('units')]);
        $this->postJson($url)->assertStatus(402)->assertJsonPath('code', 'cap_reached');

        config(['schools.plans.enterprise.limits.ai_units_monthly' => null]);
        Queue::fake();
        $this->postJson($url, [], ['Idempotency-Key' => 'regen-key-0003'])->assertStatus(202)->assertJsonPath('data.status', 'queued');
        $this->postJson($url, [], ['Idempotency-Key' => 'regen-key-0004'])->assertStatus(409)->assertJsonPath('code', 'regenerating');
        $this->assertSame(1, GenerationJob::query()->where('kind', 'regenerate')->count());
        $this->getJson("/api/ai/drafts/{$draft->id}")->assertJsonPath('data.active_job_kind', 'regenerate');
    }

    public function test_only_the_owner_or_the_admin_reviews_a_draft(): void
    {
        $owner = $this->teacher();
        $draft = $this->generatedDraft($owner);
        $q = $this->mcq($draft->id);
        $base = "/api/ai/drafts/{$draft->id}";

        $this->actAs('teacher');
        $this->putJson("{$base}/questions/{$q->id}", ['prompt' => 'x'])->assertNotFound();
        $this->postJson("{$base}/questions/{$q->id}/approve")->assertNotFound();
        $this->postJson("{$base}/approve-all-unflagged")->assertNotFound();
        $this->postJson("{$base}/publish")->assertNotFound();
        $this->postJson("{$base}/questions/{$q->id}/regenerate")->assertNotFound();

        $this->actAs('student');
        $this->postJson("{$base}/questions/{$q->id}/approve")->assertForbidden();

        $this->actAs('admin');
        $this->postJson("{$base}/questions/{$q->id}/approve")->assertOk();

        // A question of another draft is not found through this draft.
        Sanctum::actingAs($owner);
        $other = $this->generatedDraft($owner);
        $foreign = $this->mcq($other->id);
        $this->postJson("{$base}/questions/{$foreign->id}/approve")->assertNotFound();
    }

    public function test_another_center_cannot_review_a_draft(): void
    {
        $this->teacher();
        $draft = $this->generatedDraft();
        $q = $this->mcq($draft->id);

        $this->openSchool('beta');
        config(['schools.plans.enterprise.features.ai_exams' => true]);
        URL::forceRootUrl('http://beta.mudarisy.test');
        $this->app['auth']->forgetGuards();
        $this->actAs('admin');

        $this->postJson("/api/ai/drafts/{$draft->id}/questions/{$q->id}/approve")->assertNotFound();
        $this->postJson("/api/ai/drafts/{$draft->id}/publish")->assertNotFound();
    }
}
