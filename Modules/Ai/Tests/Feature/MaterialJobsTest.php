<?php

declare(strict_types=1);

namespace Modules\Ai\Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Ai\Contracts\MaterialScanner;
use Modules\Ai\Enums\MaterialStatus;
use Modules\Ai\Jobs\ExtractMaterialJob;
use Modules\Ai\Jobs\PurgeMaterialJob;
use Modules\Ai\Models\Material;
use Modules\Ai\Models\MaterialChunkRef;
use Modules\Ai\Models\MaterialSection;
use Modules\Ai\Tests\Concerns\BuildsMaterials;
use Tests\TestCase;

class MaterialJobsTest extends TestCase
{
    use BuildsMaterials;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableAi();
    }

    /** A stored material waiting for extraction, as the upload leaves it. */
    private function waiting(array $attributes = []): Material
    {
        $owner = $this->teacher();
        $path = 'centers/demo/ai-materials/test-'.uniqid();
        Storage::disk($this->aiDisk)->put($path, '%PDF-1.4 %%EOF');

        return $this->material($owner, $attributes + ['storage_path' => $path]);
    }

    private function runJob(Material $material, int $tries = 3): void
    {
        $job = new ExtractMaterialJob($material->id);
        $job->tries = $tries;
        app()->call([$job, 'handle']);
    }

    public function test_the_job_walks_uploaded_to_ready_saves_the_outline_and_chunk_refs(): void
    {
        $this->fakeAiService();
        $material = $this->waiting();

        $this->runJob($material);

        $material->refresh();
        $this->assertSame(MaterialStatus::Ready, $material->status);
        $this->assertSame(2, $material->pages_count);
        $this->assertSame(1, $material->ingest_version);
        $this->assertSame('bge-m3', $material->embed_model);
        $this->assertSame(2, MaterialSection::where('material_id', $material->id)->count());
        $this->assertSame(['c_0', 'c_1'], MaterialChunkRef::where('material_id', $material->id)->orderBy('id')->pluck('chunk_ref')->all());
        Http::assertSent(fn ($r) => str_contains($r->url(), '/ingest') && $r['version'] === 1 && $r['embed_model'] === 'bge-m3'
            && $r['sections'][0]['text'] !== '' && isset($r['sections'][0]['id']));
        // The outline keeps no text.
        $this->assertArrayNotHasKey('text', MaterialSection::first()->getAttributes());
    }

    public function test_the_status_is_extracting_while_the_ai_service_works(): void
    {
        $material = $this->waiting();
        $seen = null;
        $this->fakeAiService(['ai.test/v1/materials/extract' => function () use ($material, &$seen) {
            $seen = Material::find($material->id)->status;

            return Http::response(['pages' => 1, 'char_count' => 5, 'sections' => [['kind' => 'page', 'label' => 'صفحة 1', 'ordinal' => 1, 'text' => 'نص']]]);
        }]);

        $this->runJob($material);

        $this->assertSame(MaterialStatus::Extracting, $seen);
    }

    public function test_running_the_job_twice_does_the_work_once(): void
    {
        $this->fakeAiService();
        $material = $this->waiting();

        $this->runJob($material);
        $this->runJob($material);

        Http::assertSentCount(2); // one extract, one ingest
        $this->assertSame(2, MaterialChunkRef::count());
    }

    public function test_ai_service_down_fails_the_material_with_a_retryable_reason_on_the_last_attempt(): void
    {
        $this->fakeAiService(['ai.test/v1/materials/extract' => Http::response(['message' => 'down'], 503)]);
        $material = $this->waiting();

        $this->runJob($material, tries: 1);

        $material->refresh();
        $this->assertSame(MaterialStatus::Failed, $material->status);
        $this->assertSame('ai_unavailable', $material->failure_reason);
        $this->getJson("/api/ai/materials/{$material->id}")->assertOk()
            ->assertJsonPath('data.failure_reason', 'ai_unavailable')
            ->assertJsonPath('data.failure_message', 'المساعد غير متاح الآن. أعد المحاولة بعد قليل.')
            ->assertJsonPath('data.can_retry', true);
    }

    public function test_a_transient_failure_before_the_last_attempt_goes_back_to_uploaded_and_retries_later(): void
    {
        $this->fakeAiService(['ai.test/v1/materials/extract' => Http::response([], 502)]);
        $material = $this->waiting();

        $this->runJob($material, tries: 3);

        $this->assertSame(MaterialStatus::Uploaded, $material->refresh()->status);
        $this->assertNull($material->failure_reason);
    }

    public function test_retry_endpoint_runs_a_failed_material_again(): void
    {
        $this->fakeAiService();
        $material = $this->waiting(['status' => 'failed', 'failure_reason' => 'ai_unavailable']);

        $this->postJson("/api/ai/materials/{$material->id}/retry")->assertStatus(202)->assertJsonPath('data.status', 'ready');
        $this->postJson("/api/ai/materials/{$material->id}/retry")->assertStatus(409)->assertJsonPath('code', 'not_retryable');
    }

    public function test_retry_queues_the_job_on_the_ai_queue(): void
    {
        Queue::fake();
        $material = $this->waiting(['status' => 'failed', 'failure_reason' => 'ai_unavailable']);

        $this->postJson("/api/ai/materials/{$material->id}/retry")->assertStatus(202)->assertJsonPath('data.status', 'uploaded');
        Queue::assertPushedOn('ai', ExtractMaterialJob::class);
    }

    public function test_a_scanned_pdf_is_marked_needs_ocr(): void
    {
        $this->fakeAiService(['ai.test/v1/materials/extract' => Http::response(['message' => 'الملف عبارة عن صور', 'code' => 'scanned_no_text'], 422)]);
        $material = $this->waiting();

        $this->runJob($material);

        $this->assertSame(MaterialStatus::NeedsOcr, $material->refresh()->status);
        $this->assertSame('scanned_no_text', $material->failure_reason);
        $this->getJson("/api/ai/materials/{$material->id}")->assertJsonPath('data.failure_message', 'الملف ممسوح ضوئياً ويحتاج OCR؛ ارفع نسخة نصية أو Word.');
    }

    public function test_service_refusals_become_specific_arabic_reasons(): void
    {
        foreach ([[413, [], 'file_too_large'], [422, ['code' => 'password_protected'], 'password_protected'], [422, ['message' => 'ملف تالف'], 'corrupt']] as [$status, $body, $reason]) {
            Http::swap(new Factory); // a fresh fake: the first matching stub wins
            $this->fakeAiService(['ai.test/v1/materials/extract' => Http::response($body, $status)]);
            $material = $this->waiting();
            $this->runJob($material);
            $this->assertSame($reason, $material->refresh()->failure_reason);
            $this->assertSame(MaterialStatus::Failed, $material->status);
        }
    }

    public function test_limits_from_config_fail_the_material(): void
    {
        config(['ai.materials.max_pdf_pages' => 1]);
        $this->fakeAiService();
        $material = $this->waiting();

        $this->runJob($material);

        $this->assertSame('too_many_pages', $material->refresh()->failure_reason);
        $this->assertSame(0, MaterialSection::count());
    }

    public function test_an_empty_extraction_fails_with_no_text(): void
    {
        $this->fakeAiService(['ai.test/v1/materials/extract' => Http::response(['pages' => 1, 'char_count' => 0, 'sections' => []])]);
        $material = $this->waiting();

        $this->runJob($material);

        $this->assertSame('no_text', $material->refresh()->failure_reason);
    }

    public function test_the_virus_scan_hook_rejects_a_file_before_it_reaches_the_ai_service(): void
    {
        $this->app->bind(MaterialScanner::class, fn () => new class implements MaterialScanner
        {
            public function isClean(string $disk, string $path): bool
            {
                return false;
            }
        });
        Http::fake();
        $material = $this->waiting();

        $this->runJob($material);

        $this->assertSame('infected', $material->refresh()->failure_reason);
        Http::assertNothingSent();
    }

    public function test_a_missing_file_fails_the_material(): void
    {
        Http::fake();
        $material = $this->waiting();
        Storage::disk($this->aiDisk)->delete($material->storage_path);

        $this->runJob($material);

        $this->assertSame('file_missing', $material->refresh()->failure_reason);
    }

    public function test_a_chunk_ref_pointing_outside_the_material_is_ignored(): void
    {
        $this->fakeAiService(['ai.test/v1/materials/*/ingest' => Http::response([
            'version' => 1, 'chunks' => 1, 'chunk_refs' => [['section_id' => 99999, 'chunk_ref' => 'c_evil', 'ordinal' => 0]],
        ])]);
        $material = $this->waiting();

        $this->runJob($material);

        $this->assertSame(MaterialStatus::Ready, $material->refresh()->status);
        $this->assertSame(0, MaterialChunkRef::count());
    }

    public function test_the_ai_service_not_configured_never_breaks_the_upload_request(): void
    {
        config(['services.ai.url' => null]);
        Http::fake();
        $this->teacher();
        $this->runJobViaUpload();
    }

    private function runJobViaUpload(): void
    {
        $id = $this->postJson('/api/ai/materials', ['file' => $this->docx()])->assertCreated()->json('data.id');
        // The sync queue ran the job once (attempt 1 of 3): it waits for a retry instead of failing the request.
        $this->assertContains(Material::findOrFail($id)->status, [MaterialStatus::Uploaded, MaterialStatus::Failed]);
        Http::assertNothingSent();
    }

    public function test_a_pdf_original_is_removed_once_ready_and_the_light_size_is_what_counts(): void
    {
        $this->fakeAiService();
        $material = $this->waiting(['size_bytes' => 50000]);
        $path = $material->storage_path;

        $this->runJob($material);

        $material->refresh();
        $this->assertSame(MaterialStatus::Ready, $material->status);
        $this->assertTrue($material->original_removed);
        $this->assertNull($material->storage_path);
        Storage::disk($this->aiDisk)->assertMissing($path);
        $this->assertGreaterThan(0, $material->size_bytes);
        $this->assertLessThan(1000, $material->size_bytes);
    }

    public function test_deleting_removes_the_file_rows_and_asks_the_ai_service_to_delete_vectors(): void
    {
        $this->fakeAiService();
        config(['ai.materials.keep_originals' => true]); // this test is about deleting a kept file
        $material = $this->waiting();
        $this->runJob($material);
        $path = $material->storage_path;
        Storage::disk($this->aiDisk)->assertExists($path);

        $this->deleteJson("/api/ai/materials/{$material->id}")->assertNoContent();

        Storage::disk($this->aiDisk)->assertMissing($path);
        $this->assertSame(0, Material::count());
        $this->assertSame(0, MaterialSection::count());
        $this->assertSame(0, MaterialChunkRef::count());
        $this->assertSame(1, Material::withTrashed()->count());
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && $r->url() === "http://ai.test/v1/materials/{$material->id}"
            && $r->hasHeader('X-Center-Id', 'demo'));
        $this->getJson("/api/ai/materials/{$material->id}")->assertNotFound();
    }

    public function test_delete_queues_a_purge_and_survives_the_ai_service_being_down(): void
    {
        Queue::fake();
        $material = $this->waiting();

        $this->deleteJson("/api/ai/materials/{$material->id}")->assertNoContent();
        Queue::assertPushedOn('ai', PurgeMaterialJob::class, fn ($job) => $job->materialId === $material->id);

        Http::fake(['ai.test/*' => Http::response([], 503)]);
        $job = new PurgeMaterialJob($material->id);
        app()->call([$job, 'handle']); // transient and not last: released, no exception
        $this->assertTrue(true);
    }

    public function test_deleting_stays_possible_when_the_plan_no_longer_has_the_feature(): void
    {
        Queue::fake();
        $material = $this->waiting();
        config(['schools.plans.enterprise.features.ai_exams' => false]);

        $this->deleteJson("/api/ai/materials/{$material->id}")->assertNoContent();
    }
}
