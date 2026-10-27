<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Modules\Ai\Exceptions\CapReached;
use Modules\Ai\Exceptions\PlanFeatureRequired;
use Modules\Ai\Models\Material;
use Modules\Tenancy\Models\School;

/** What the center's plan allows for AI exams: the `ai_exams` flag and the material caps. */
final class AiEntitlements
{
    /** Only a true flag opens the feature; 'soon' and false both stay closed. */
    public function ensureEnabled(): void
    {
        $flag = $this->school()->feature('ai_exams');
        if ($flag !== true) {
            throw new PlanFeatureRequired(soon: $flag === 'soon');
        }
    }

    /**
     * What a file will occupy once processed: a PDF's original is removed after extraction, so only
     * its light text is kept (estimated here; the real size replaces it when extraction ends).
     */
    public function expectedStoredBytes(string $kind, int $bytes): int
    {
        if ($kind !== 'pdf' || config('ai.materials.keep_originals')) {
            return $bytes;
        }

        return (int) ceil($bytes * (float) config('ai.materials.light_estimate_ratio'));
    }

    public function ensureCanStore(int $bytes): void
    {
        $school = $this->school();
        $usage = $this->usage();

        $max = $school->limit('ai_materials_max');
        if ($max !== null && $usage['materials'] >= $max) {
            throw new CapReached('ai_materials_max', 'بلغ مركزك الحد الأقصى لعدد المواد في الباقة. احذف مادة أو رقِّ الباقة.');
        }
        $storageMb = $school->limit('ai_material_storage_mb');
        if ($storageMb !== null && $storageMb * 1024 * 1024 < $usage['storage_bytes'] + $bytes) {
            throw new CapReached('ai_material_storage_mb', 'مساحة المواد في باقتك لا تكفي لهذا الملف. احذف مواد قديمة أو رقِّ الباقة.');
        }
    }

    /** @return array{materials: int, storage_bytes: int} what the whole center holds now */
    public function usage(): array
    {
        $row = Material::query()->selectRaw('count(*) as materials, coalesce(sum(size_bytes), 0) as storage_bytes')->toBase()->first();

        return ['materials' => (int) $row->materials, 'storage_bytes' => (int) $row->storage_bytes];
    }

    /** @return array<string, mixed> the numbers the upload screen shows */
    public function limits(): array
    {
        $school = $this->school();
        $usage = $this->usage();

        return [
            'allowed_types' => ['pdf', 'docx', 'xlsx'],
            'max_file_mb' => (int) config('ai.materials.max_file_mb'),
            'max_office_file_mb' => (int) config('ai.materials.max_office_file_mb'),
            'chunk_mb' => (int) config('ai.materials.chunk_mb'),
            'keep_originals' => (bool) config('ai.materials.keep_originals'),
            'materials_count' => $usage['materials'],
            'materials_max' => $school->limit('ai_materials_max'),
            'storage_used_mb' => round($usage['storage_bytes'] / 1048576, 2),
            'storage_cap_mb' => $school->limit('ai_material_storage_mb'),
        ];
    }

    private function school(): School
    {
        $school = tenant();
        if (! $school instanceof School) {
            throw new \LogicException('AI materials are only used inside a school.');
        }

        return $school;
    }
}
