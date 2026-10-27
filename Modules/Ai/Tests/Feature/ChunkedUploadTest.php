<?php

declare(strict_types=1);

namespace Modules\Ai\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Ai\Actions\ExtractMaterial;
use Modules\Ai\Actions\PurgeStaleUploads;
use Modules\Ai\Enums\MaterialStatus;
use Modules\Ai\Jobs\ExtractMaterialJob;
use Modules\Ai\Models\Material;
use Modules\Ai\Models\MaterialUpload;
use Modules\Ai\Tests\Concerns\BuildsMaterials;
use Tests\TestCase;

class ChunkedUploadTest extends TestCase
{
    use BuildsMaterials;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableAi();
        config(['ai.materials.chunk_mb' => 1]); // pieces of 1 MB keep the tests light
    }

    /** A PDF of about $mb megabytes that passes the content checks (header, %%EOF, no /Encrypt). */
    private function bigPdf(float $mb): string
    {
        return "%PDF-1.4\n".str_repeat('x', (int) ($mb * 1048576)).self::eof();
    }

    private static function eof(): string
    {
        return "\ntrailer<<>>\n%%EOF\n";
    }

    private function start(string $bytes, string $name = 'كتاب.pdf', array $extra = []): array
    {
        return $this->postJson('/api/ai/materials/uploads', ['filename' => $name, 'size' => strlen($bytes), 'title' => 'كتاب كبير'] + $extra)
            ->assertCreated()->json('data');
    }

    private function sendChunk(string $id, int $index, string $bytes, ?string $sum = null)
    {
        return $this->call('PUT', "/api/ai/materials/uploads/{$id}/chunks/{$index}", [], [], [], [
            'HTTP_X-Chunk-Sha256' => $sum ?? hash('sha256', $bytes), 'CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json',
        ], $bytes);
    }

    private function sendAll(array $upload, string $bytes): void
    {
        foreach (str_split($bytes, $upload['chunk_bytes']) as $i => $piece) {
            $this->sendChunk($upload['id'], $i, $piece)->assertOk();
        }
    }

    public function test_a_large_pdf_goes_up_in_pieces_and_extraction_is_queued_on_the_ai_queue(): void
    {
        Queue::fake();
        $this->teacher();
        $bytes = $this->bigPdf(2.5);
        $upload = $this->start($bytes);
        $this->assertSame(3, $upload['total_chunks']);

        foreach (str_split($bytes, $upload['chunk_bytes']) as $i => $piece) {
            $this->sendChunk($upload['id'], $i, $piece)->assertOk()->assertJsonPath('data.next_index', $i + 1);
        }
        $this->assertSame(strlen($bytes), $this->getJson("/api/ai/materials/uploads/{$upload['id']}")->json('data.received_bytes'));

        $material = $this->postJson("/api/ai/materials/uploads/{$upload['id']}/complete")->assertCreated()
            ->assertJsonPath('data.status', 'uploaded')->assertJsonPath('data.title', 'كتاب كبير')->assertJsonPath('data.size_bytes', strlen($bytes))
            ->json('data');

        $stored = Material::findOrFail($material['id']);
        $this->assertSame($bytes, Storage::disk($this->aiDisk)->get($stored->storage_path));
        $this->assertSame(0, MaterialUpload::count());
        $this->assertSame([], Storage::disk($this->aiDisk)->allFiles('centers/demo/ai-uploads'), 'the temp file is gone');
        Queue::assertPushedOn('ai', ExtractMaterialJob::class, fn ($job) => $job->materialId === $stored->id);
    }

    public function test_after_extraction_the_original_is_removed_and_only_the_light_size_is_counted(): void
    {
        $this->teacher();
        $this->fakeAiService();
        config(['queue.default' => 'sync']);
        $bytes = $this->bigPdf(2.2);
        $upload = $this->start($bytes);
        $this->sendAll($upload, $bytes);

        $id = $this->postJson("/api/ai/materials/uploads/{$upload['id']}/complete")->assertCreated()
            ->assertJsonPath('data.status', 'ready')->assertJsonPath('data.original_removed', true)->json('data.id');

        $material = Material::findOrFail($id);
        $this->assertNull($material->storage_path);
        $this->assertTrue($material->original_removed);
        $this->assertSame(strlen($bytes), $material->size_original);
        $this->assertLessThan(2000, $material->size_bytes, 'the plan counts the light text, not the 2 MB original');
        $this->assertSame([], Storage::disk($this->aiDisk)->allFiles(), 'no big file is left on the disk');
        $this->getJson("/api/ai/materials/{$id}/download")->assertNotFound();
        $this->assertEquals(round($material->size_bytes / 1048576, 2), $this->getJson('/api/ai/materials')->json('limits.storage_used_mb'));
    }

    public function test_keep_originals_keeps_the_file_and_its_size(): void
    {
        $this->teacher();
        $this->fakeAiService();
        config(['queue.default' => 'sync', 'ai.materials.keep_originals' => true]);
        $bytes = $this->bigPdf(1.2);
        $upload = $this->start($bytes);
        $this->sendAll($upload, $bytes);

        $id = $this->postJson("/api/ai/materials/uploads/{$upload['id']}/complete")->assertCreated()
            ->assertJsonPath('data.original_removed', false)->json('data.id');

        $material = Material::findOrFail($id);
        Storage::disk($this->aiDisk)->assertExists($material->storage_path);
        $this->assertSame(strlen($bytes), $material->size_bytes);
    }

    public function test_the_original_is_removed_when_the_pdf_is_scanned(): void
    {
        $this->teacher();
        $this->fakeAiService(['ai.test/v1/materials/extract' => Http::response(['message' => 'ممسوح', 'code' => 'scanned_no_text'], 422)]);
        config(['queue.default' => 'sync']);
        $bytes = $this->bigPdf(1.1);
        $upload = $this->start($bytes);
        $this->sendAll($upload, $bytes);
        $id = $this->postJson("/api/ai/materials/uploads/{$upload['id']}/complete")->assertCreated()->assertJsonPath('data.status', 'needs_ocr')->json('data.id');
        $this->assertTrue(Material::findOrFail($id)->original_removed);
        $this->assertSame([], Storage::disk($this->aiDisk)->allFiles());
    }

    public function test_a_service_outage_keeps_the_original_so_the_retry_can_run(): void
    {
        $this->teacher();
        Http::fake(['ai.test/*' => Http::response('down', 503)]);
        config(['queue.default' => 'sync']);
        $bytes = $this->bigPdf(1.1);
        $other = $this->start($bytes, 'ثاني.pdf');
        $this->sendAll($other, $bytes);
        $second = $this->postJson("/api/ai/materials/uploads/{$other['id']}/complete")->assertCreated()->json('data.id');
        $m = Material::findOrFail($second);
        $this->assertSame(MaterialStatus::Uploaded, $m->status, 'released for the next attempt');
        $this->assertFalse($m->original_removed, 'a service outage keeps the file so the retry can run');
        Storage::disk($this->aiDisk)->assertExists($m->storage_path);
    }

    public function test_word_and_excel_keep_their_original(): void
    {
        $this->teacher();
        $this->fakeAiService();
        config(['queue.default' => 'sync']);
        $docx = $this->docx();
        $bytes = file_get_contents($docx->getRealPath());
        $upload = $this->start($bytes, 'درس.docx');
        $this->sendAll($upload, $bytes);
        $id = $this->postJson("/api/ai/materials/uploads/{$upload['id']}/complete")->assertCreated()->assertJsonPath('data.original_removed', false)->json('data.id');
        Storage::disk($this->aiDisk)->assertExists(Material::findOrFail($id)->storage_path);
    }

    public function test_a_repeated_piece_is_acknowledged_without_being_written_twice(): void
    {
        Queue::fake();
        $this->teacher();
        $bytes = $this->bigPdf(1.5);
        $upload = $this->start($bytes);
        $pieces = str_split($bytes, $upload['chunk_bytes']);

        $this->sendChunk($upload['id'], 0, $pieces[0])->assertOk();
        $this->sendChunk($upload['id'], 0, $pieces[0])->assertOk()->assertJsonPath('data.next_index', 1)->assertJsonPath('data.received_bytes', strlen($pieces[0]));
        $this->sendChunk($upload['id'], 1, $pieces[1])->assertOk();

        $id = $this->postJson("/api/ai/materials/uploads/{$upload['id']}/complete")->assertCreated()->json('data.id');
        $this->assertSame($bytes, Storage::disk($this->aiDisk)->get(Material::findOrFail($id)->storage_path));
    }

    public function test_a_gap_a_bad_checksum_and_a_wrong_size_are_refused_and_nothing_is_written(): void
    {
        $this->teacher();
        $bytes = $this->bigPdf(2.5);
        $upload = $this->start($bytes);
        $pieces = str_split($bytes, $upload['chunk_bytes']);

        $this->sendChunk($upload['id'], 1, $pieces[1])->assertStatus(409)->assertJsonPath('code', 'out_of_order')->assertJsonPath('next_index', 0);
        $this->sendChunk($upload['id'], 0, $pieces[0], str_repeat('0', 64))->assertStatus(422)->assertJsonPath('code', 'checksum_mismatch');
        $this->sendChunk($upload['id'], 0, substr($pieces[0], 0, 1000))->assertStatus(422)->assertJsonPath('code', 'bad_chunk_size');
        $this->sendChunk($upload['id'], 0, $pieces[0].'extra')->assertStatus(422)->assertJsonPath('code', 'bad_chunk_size');
        $this->assertSame(0, filesize(Storage::disk($this->aiDisk)->path(MaterialUpload::firstOrFail()->temp_path)));
        $this->call('PUT', "/api/ai/materials/uploads/{$upload['id']}/chunks/0", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], $pieces[0])->assertStatus(422);

        $this->sendChunk($upload['id'], 0, $pieces[0])->assertOk(); // the resend after the refusals works
    }

    public function test_complete_before_all_pieces_is_refused(): void
    {
        $this->teacher();
        $bytes = $this->bigPdf(1.5);
        $upload = $this->start($bytes);
        $this->sendChunk($upload['id'], 0, str_split($bytes, $upload['chunk_bytes'])[0])->assertOk();

        $this->postJson("/api/ai/materials/uploads/{$upload['id']}/complete")->assertStatus(409)->assertJsonPath('code', 'incomplete')->assertJsonPath('next_index', 1);
    }

    public function test_the_whole_file_checksum_is_verified_and_a_wrong_file_is_discarded(): void
    {
        $this->teacher();
        $bytes = $this->bigPdf(1.2);
        $upload = $this->start($bytes, 'كتاب.pdf', ['sha256' => str_repeat('a', 64)]);
        $this->sendAll($upload, $bytes);

        $this->postJson("/api/ai/materials/uploads/{$upload['id']}/complete")->assertStatus(422)->assertJsonPath('code', 'checksum_mismatch');
        $this->assertSame(0, Material::count());
        $this->assertSame([], Storage::disk($this->aiDisk)->allFiles());
    }

    public function test_content_is_checked_at_the_end_a_fake_pdf_is_refused_and_removed(): void
    {
        $this->teacher();
        $bytes = str_repeat('not a pdf ', 120000);
        $upload = $this->start($bytes);
        $this->sendAll($upload, $bytes);

        $this->postJson("/api/ai/materials/uploads/{$upload['id']}/complete")->assertStatus(422);
        $this->assertSame(0, Material::count());
        $this->assertSame([], Storage::disk($this->aiDisk)->allFiles());
    }

    public function test_the_size_caps_apply_at_the_start(): void
    {
        $this->teacher();
        config(['ai.materials.max_file_mb' => 100]);
        $this->postJson('/api/ai/materials/uploads', ['filename' => 'a.pdf', 'size' => 101 * 1048576])->assertStatus(422)->assertJsonValidationErrors('size');
        $this->postJson('/api/ai/materials/uploads', ['filename' => 'a.docx', 'size' => 30 * 1048576])->assertStatus(422);
        $this->postJson('/api/ai/materials/uploads', ['filename' => 'a.xlsm', 'size' => 1000])->assertStatus(422);
        $this->postJson('/api/ai/materials/uploads', ['filename' => 'a.pdf', 'size' => 99 * 1048576])->assertCreated();
    }

    public function test_the_plan_storage_check_uses_the_light_size_estimate_for_pdfs(): void
    {
        $this->teacher();
        config(['schools.plans.enterprise.limits.ai_material_storage_mb' => 20, 'ai.materials.light_estimate_ratio' => 0.1]);
        // 100 MB original -> ~10 MB expected, fits a 20 MB plan; a Word file counts in full.
        $this->postJson('/api/ai/materials/uploads', ['filename' => 'a.pdf', 'size' => 100 * 1048576])->assertCreated();
        $this->postJson('/api/ai/materials/uploads', ['filename' => 'a.docx', 'size' => 21 * 1048576])->assertStatus(402)->assertJsonPath('cap', 'ai_material_storage_mb');
    }

    public function test_only_the_owner_can_use_an_upload_and_students_cannot_start_one(): void
    {
        $this->teacher();
        $bytes = $this->bigPdf(1.2);
        $upload = $this->start($bytes);

        $this->actAs('teacher');
        $this->sendChunk($upload['id'], 0, substr($bytes, 0, $upload['chunk_bytes']))->assertNotFound();
        $this->getJson("/api/ai/materials/uploads/{$upload['id']}")->assertNotFound();
        $this->postJson("/api/ai/materials/uploads/{$upload['id']}/complete")->assertNotFound();
        $this->deleteJson("/api/ai/materials/uploads/{$upload['id']}")->assertNotFound();

        $this->actAs('student');
        $this->postJson('/api/ai/materials/uploads', ['filename' => 'a.pdf', 'size' => 1000])->assertForbidden();
    }

    public function test_abort_removes_the_temp_file(): void
    {
        $this->teacher();
        $bytes = $this->bigPdf(1.2);
        $upload = $this->start($bytes);
        $this->sendChunk($upload['id'], 0, substr($bytes, 0, $upload['chunk_bytes']))->assertOk();

        $this->deleteJson("/api/ai/materials/uploads/{$upload['id']}")->assertNoContent();
        $this->assertSame(0, MaterialUpload::count());
        $this->assertSame([], Storage::disk($this->aiDisk)->allFiles());
    }

    public function test_abandoned_uploads_expire_and_the_cleanup_removes_them_with_their_files(): void
    {
        $this->teacher();
        $bytes = $this->bigPdf(1.2);
        $old = $this->start($bytes);
        $this->sendChunk($old['id'], 0, substr($bytes, 0, $old['chunk_bytes']))->assertOk();
        $fresh = $this->start($bytes, 'جديد.pdf');
        MaterialUpload::whereKey($old['id'])->update(['expires_at' => now()->subMinute()]);

        $this->sendChunk($old['id'], 1, substr($bytes, $old['chunk_bytes']))->assertStatus(410)->assertJsonPath('code', 'upload_expired');
        $this->assertSame(1, app(PurgeStaleUploads::class)->handle());
        $this->assertSame([$fresh['id']], MaterialUpload::pluck('id')->all());
        $this->assertCount(1, Storage::disk($this->aiDisk)->allFiles('centers/demo/ai-uploads'));
    }

    public function test_the_scheduler_runs_the_cleanup_for_every_school(): void
    {
        $this->assertTrue(collect(app(Schedule::class)->events())
            ->contains(fn ($e) => str_contains((string) $e->command, 'schools:dispatch ai:purge-stale-uploads')));
    }

    public function test_the_extraction_job_does_not_hold_the_big_file_in_memory(): void
    {
        // The AI client streams the stored file (attach with a stream); the action never reads it whole.
        $this->teacher();
        $this->fakeAiService();
        $bytes = $this->bigPdf(2.2);
        $upload = $this->start($bytes);
        $this->sendAll($upload, $bytes);
        Queue::fake();
        $id = $this->postJson("/api/ai/materials/uploads/{$upload['id']}/complete")->assertCreated()->json('data.id');
        $before = memory_get_peak_usage(true);
        app(ExtractMaterial::class)->handle($id);
        $this->assertLessThan(2 * 1048576 + 32 * 1048576, memory_get_peak_usage(true) - $before + 1);
    }
}

function eof(): string
{
    return "\ntrailer<<>>\n%%EOF\n";
}
