<?php

declare(strict_types=1);

namespace Modules\Ai\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Modules\Ai\Jobs\ExtractMaterialJob;
use Modules\Ai\Models\Material;
use Modules\Ai\Tests\Concerns\BuildsMaterials;
use Modules\Auth\Models\User;
use Tests\TestCase;

class MaterialUploadTest extends TestCase
{
    use BuildsMaterials;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableAi();
    }

    public function test_a_teacher_uploads_a_docx_to_private_storage_and_extraction_is_queued(): void
    {
        Queue::fake();
        $teacher = $this->teacher();

        $response = $this->postJson('/api/ai/materials', ['file' => $this->docx(), 'title' => 'الدرس الأول'])->assertCreated();

        $response->assertJsonPath('data.status', 'uploaded')->assertJsonPath('data.kind', 'docx')->assertJsonPath('data.title', 'الدرس الأول');
        $material = Material::firstOrFail();
        $this->assertSame($teacher->id, $material->owner_user_id);
        $this->assertStringStartsWith('centers/demo/ai-materials/', $material->storage_path);
        Storage::disk($this->aiDisk)->assertExists($material->storage_path);
        $this->assertFalse(Storage::disk('public')->exists($material->storage_path));
        Queue::assertPushedOn('ai', ExtractMaterialJob::class, fn ($job) => $job->materialId === $material->id);
    }

    public function test_the_whole_pipeline_runs_to_ready_and_the_status_is_visible_on_get(): void
    {
        $this->fakeAiService();
        $this->teacher();

        $id = $this->postJson('/api/ai/materials', ['file' => $this->pdf()])->assertCreated()
            ->assertJsonPath('data.status', 'ready')->json('data.id');

        $this->getJson("/api/ai/materials/{$id}")->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.pages_count', 2)
            ->assertJsonPath('data.sections.0.label', 'صفحة 1')
            ->assertJsonCount(2, 'data.sections');
        $this->assertSame(2, Material::findOrFail($id)->chunkRefs()->count());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/materials/extract')
            && $r->hasHeader('Authorization', 'Bearer service-token') && $r->hasHeader('X-Center-Id', 'demo'));
    }

    public function test_unsupported_and_dangerous_files_are_refused_with_a_reason_and_nothing_is_stored(): void
    {
        Queue::fake();
        $this->teacher();
        $encrypted = $this->pdf('محمي.pdf', '/Encrypt 5 0 R');
        $macro = $this->xlsx('ماكرو.xlsx', ['xl/vbaProject.bin' => 'x']);
        $cases = [
            'plain text' => [UploadedFile::fake()->createWithContent('a.txt', 'hello'), 'غير مدعوم'],
            'xlsm by name' => [$this->xlsx('a.xlsm'), 'ماكرو'],
            'macro inside' => [$macro, 'ماكرو'],
            'old doc' => [UploadedFile::fake()->createWithContent('a.doc', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1abc"), 'القديمة'],
            'docx content named pdf' => [UploadedFile::fake()->createWithContent('a.pdf', $this->docx()->getContent()), 'لا يطابق'],
            'pdf bytes named docx' => [UploadedFile::fake()->createWithContent('a.docx', "%PDF-1.4\n%%EOF"), 'لا يطابق'],
            'exe renamed pdf' => [UploadedFile::fake()->createWithContent('a.pdf', 'MZ'.str_repeat('x', 100)), 'غير مدعوم'],
            'encrypted pdf' => [$encrypted, 'كلمة مرور'],
            'ole encrypted docx' => [UploadedFile::fake()->createWithContent('a.docx', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1abc"), 'كلمة مرور'],
            'corrupt zip' => [UploadedFile::fake()->createWithContent('a.docx', "PK\x03\x04garbage"), 'تالف'],
            'zip without word part' => [$this->zipFile('a.docx', ['foo.txt' => 'x']), 'غير مدعوم'],
        ];

        foreach ($cases as $label => [$file, $reason]) {
            $response = $this->postJson('/api/ai/materials', ['file' => $file])->assertStatus(422);
            $this->assertStringContainsString($reason, $response->json('errors.file.0'), $label);
        }
        $this->assertSame(0, Material::count());
        $this->assertSame([], Storage::disk($this->aiDisk)->allFiles());
        Queue::assertNothingPushed();
    }

    public function test_a_file_over_the_size_limit_is_refused(): void
    {
        $this->teacher();
        config(['ai.materials.max_file_mb' => 1]);

        $response = $this->postJson('/api/ai/materials', ['file' => UploadedFile::fake()->create('big.pdf', 2048)])->assertStatus(422);

        $this->assertStringContainsString('الحد المسموح', $response->json('errors.file.0'));
        $this->assertSame(0, Material::count());
    }

    public function test_a_missing_file_is_a_validation_error(): void
    {
        $this->teacher();
        $this->postJson('/api/ai/materials', [])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_the_materials_count_cap_stops_a_further_upload_with_402(): void
    {
        Queue::fake();
        config(['schools.plans.enterprise.limits.ai_materials_max' => 1]);
        $this->teacher();

        $this->postJson('/api/ai/materials', ['file' => $this->docx()])->assertCreated();
        $this->postJson('/api/ai/materials', ['file' => $this->docx('ثان.docx')])
            ->assertStatus(402)->assertJsonPath('code', 'cap_reached')->assertJsonPath('cap', 'ai_materials_max');
        $this->assertSame(1, Material::count());
    }

    public function test_the_storage_cap_stops_an_upload_that_does_not_fit(): void
    {
        Queue::fake();
        config(['schools.plans.enterprise.limits.ai_material_storage_mb' => 0]);
        $this->teacher();

        $this->postJson('/api/ai/materials', ['file' => $this->docx()])
            ->assertStatus(402)->assertJsonPath('cap', 'ai_material_storage_mb');
        $this->assertSame(0, Material::count());
        $this->assertSame([], Storage::disk($this->aiDisk)->allFiles());
    }

    public function test_the_plan_flag_off_or_soon_answers_403_plan_feature(): void
    {
        $this->teacher();

        config(['schools.plans.enterprise.features.ai_exams' => false]);
        $this->postJson('/api/ai/materials', ['file' => $this->docx()])->assertForbidden()->assertJsonPath('code', 'plan_feature');
        $this->getJson('/api/ai/materials')->assertForbidden()->assertJsonPath('code', 'plan_feature');

        config(['schools.plans.enterprise.features.ai_exams' => 'soon']);
        $this->postJson('/api/ai/materials', ['file' => $this->docx()])->assertForbidden()
            ->assertJsonPath('code', 'plan_feature')->assertJsonPath('soon', true);
        $this->assertSame(0, Material::count());
    }

    public function test_a_school_can_be_switched_on_alone_by_the_operator(): void
    {
        Queue::fake();
        config(['schools.plans.enterprise.features.ai_exams' => false]);
        $this->school->features = ['ai_exams' => true];
        $this->school->save();
        $this->teacher();

        $this->postJson('/api/ai/materials', ['file' => $this->docx()])->assertCreated();
    }

    public function test_students_and_parents_have_no_access(): void
    {
        foreach (['student', 'parent'] as $role) {
            $this->actAs($role);
            $this->postJson('/api/ai/materials', ['file' => $this->docx()])->assertForbidden();
            $this->getJson('/api/ai/materials')->assertForbidden();
        }
    }

    public function test_a_teacher_sees_only_their_materials_and_an_admin_sees_all(): void
    {
        $mine = $this->material($this->teacher(), ['title' => 'مادتي']);
        $other = $this->material(User::factory()->create(['role' => 'teacher']), ['title' => 'مادة زميل']);

        $this->getJson('/api/ai/materials')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->getJson("/api/ai/materials/{$other->id}")->assertNotFound();
        $this->deleteJson("/api/ai/materials/{$other->id}")->assertNotFound();
        $this->postJson("/api/ai/materials/{$other->id}/retry")->assertNotFound();
        $this->getJson("/api/ai/materials/{$other->id}/download")->assertNotFound();

        $this->actAs('admin');
        $this->getJson('/api/ai/materials')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.owner.name', $other->owner->name);
        $this->getJson("/api/ai/materials/{$other->id}")->assertOk();
    }

    public function test_another_center_cannot_see_or_fetch_a_material(): void
    {
        $this->fakeAiService();
        $this->teacher();
        $id = $this->postJson('/api/ai/materials', ['file' => $this->pdf()])->assertCreated()->json('data.id');

        $beta = $this->openSchool('beta');
        config(['schools.plans.enterprise.features.ai_exams' => true]);
        URL::forceRootUrl('http://beta.mudarisy.test');
        $this->app['auth']->forgetGuards();
        $this->assertSame(0, Material::count());
        $this->actAs('admin');

        $this->getJson('/api/ai/materials')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/ai/materials/{$id}")->assertNotFound();
        $this->getJson("/api/ai/materials/{$id}/download")->assertNotFound();
        $this->deleteJson("/api/ai/materials/{$id}")->assertNotFound();
        $this->assertSame('beta', $beta->id);
    }

    public function test_the_list_is_cursor_paginated_and_reports_the_limits(): void
    {
        $teacher = $this->teacher();
        config(['schools.plans.enterprise.limits.ai_materials_max' => 100]);
        for ($i = 0; $i < 27; $i++) {
            $this->material($teacher, ['title' => "م{$i}"]);
        }

        $first = $this->getJson('/api/ai/materials')->assertOk()->assertJsonCount(25, 'data')
            ->assertJsonPath('limits.materials_count', 27)->assertJsonPath('limits.materials_max', 100)
            ->assertJsonPath('limits.max_file_mb', 25)->assertJsonPath('limits.allowed_types', ['pdf', 'docx', 'xlsx']);
        $next = $first->json('meta.next_cursor');
        $this->assertNotNull($next);
        $this->getJson('/api/ai/materials?cursor='.$next)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/ai/materials?status=ready')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/ai/materials?status=nonsense')->assertStatus(422);
    }

    public function test_the_same_idempotency_key_uploads_once(): void
    {
        Queue::fake();
        $this->teacher();
        $headers = ['Idempotency-Key' => 'upload-key-0001'];

        $a = $this->postJson('/api/ai/materials', ['file' => $this->docx()], $headers)->assertCreated();
        $b = $this->postJson('/api/ai/materials', ['file' => $this->docx()], $headers)->assertCreated();

        $this->assertSame($a->json('data.id'), $b->json('data.id'));
        $this->assertSame(1, Material::count());
    }

    public function test_the_owner_downloads_their_file_through_an_authorised_route_only(): void
    {
        Queue::fake();
        $this->teacher();
        $id = $this->postJson('/api/ai/materials', ['file' => $this->docx('درس.docx')])->assertCreated()->json('data.id');

        $this->get("/api/ai/materials/{$id}/download")->assertOk()->assertHeader('content-disposition', 'attachment; filename=drs.docx; filename*=utf-8\'\'%D8%AF%D8%B1%D8%B3.docx');
    }
}
