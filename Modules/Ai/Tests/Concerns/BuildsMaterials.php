<?php

declare(strict_types=1);

namespace Modules\Ai\Tests\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Ai\Models\Material;
use Modules\Auth\Models\User;

trait BuildsMaterials
{
    private string $aiDisk = 'ai_materials_test';

    /** The AI feature on for the test school, a fake private disk, a configured (fake) AI service. */
    private function enableAi(): void
    {
        Storage::fake($this->aiDisk);
        config([
            'queue.default' => 'sync',
            'ai.disk' => $this->aiDisk,
            'schools.plans.enterprise.features.ai_exams' => true,
            'services.ai.url' => 'http://ai.test',
            'services.ai.token' => 'service-token',
        ]);
    }

    private function teacher(): User
    {
        $user = User::factory()->create(['role' => 'teacher']);
        Sanctum::actingAs($user);

        return $user;
    }

    private function actAs(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function docx(string $name = 'درس.docx'): UploadedFile
    {
        return $this->zipFile($name, ['[Content_Types].xml' => '<Types/>', 'word/document.xml' => '<w:document/>']);
    }

    private function xlsx(string $name = 'اسئلة.xlsx', array $extra = []): UploadedFile
    {
        return $this->zipFile($name, ['[Content_Types].xml' => '<Types/>', 'xl/workbook.xml' => '<workbook/>', 'xl/worksheets/sheet1.xml' => '<w/>'] + $extra);
    }

    private function pdf(string $name = 'ملخص.pdf', string $extra = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<{$extra}>>\n%%EOF\n");
    }

    /** @param array<string, string> $entries */
    private function zipFile(string $name, array $entries): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ai');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $entry => $content) {
            $zip->addFromString($entry, $content);
        }
        $zip->close();
        $content = file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent($name, $content);
    }

    /** A healthy AI service: one-page extraction, ingest with one chunk per section, delete. */
    private function fakeAiService(array $overrides = []): void
    {
        Http::fake($overrides + [
            'ai.test/v1/materials/extract' => Http::response([
                'material_ref' => 'm_1', 'pages' => 2, 'char_count' => 120, 'needs_text_review' => false, 'warnings' => [],
                'sections' => [
                    ['kind' => 'page', 'label' => 'صفحة 1', 'ordinal' => 1, 'text' => str_repeat('نص الدرس الأول ', 4)],
                    ['kind' => 'page', 'label' => 'صفحة 2', 'ordinal' => 2, 'text' => str_repeat('نص الدرس الثاني ', 4)],
                ],
            ]),
            'ai.test/v1/materials/*/ingest' => fn ($request) => Http::response([
                'material_id' => 1, 'version' => $request['version'], 'sections' => 2, 'chunks' => 2,
                'chunk_refs' => collect($request['sections'])->map(fn ($s, $i) => ['section_id' => $s['id'], 'chunk_ref' => "c_{$i}", 'ordinal' => 0])->all(),
            ]),
            'ai.test/v1/materials/*' => Http::response(['deleted_chunks' => 2]),
        ]);
    }

    private function material(User $owner, array $attributes = []): Material
    {
        $material = new Material($attributes + [
            'owner_user_id' => $owner->id, 'title' => 'مادة', 'original_name' => 'a.pdf', 'kind' => 'pdf',
            'mime' => 'application/pdf', 'size_bytes' => 1000, 'storage_path' => null, 'sha256' => str_repeat('a', 64),
            'excel_mode' => 'auto',
        ]);
        $material->status = $attributes['status'] ?? 'uploaded';
        $material->save();

        return $material->refresh();
    }
}
