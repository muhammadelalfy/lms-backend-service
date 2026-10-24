<?php

declare(strict_types=1);

namespace Modules\Ai\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Ai\Contracts\MaterialScanner;
use Modules\Ai\DTOs\ExtractedMaterial;
use Modules\Ai\Enums\MaterialKind;
use Modules\Ai\Enums\MaterialStatus;
use Modules\Ai\Events\MaterialReady;
use Modules\Ai\Exceptions\AiException;
use Modules\Ai\Models\Material;
use Modules\Ai\Models\MaterialChunkRef;
use Modules\Ai\Models\MaterialSection;
use Modules\Ai\Services\AiClient;
use Modules\Ai\Support\FailureReason;

/**
 * Takes a stored material from `uploaded` to `ready`: virus scan, extraction in the AI service, the
 * outline saved here, then ingestion (chunks and vectors) in the AI service. The extracted text only
 * passes through memory between the two calls; this app keeps just the outline and chunk references.
 * No database transaction is open while the AI service is called.
 */
final class ExtractMaterial
{
    public function __construct(private readonly AiClient $ai, private readonly MaterialScanner $scanner) {}

    /**
     * Safe to run twice: only a material still waiting (or mid-extraction after a crash) is worked on.
     *
     * @param  bool  $final  the last attempt: a transient AI failure then fails the material instead of being retried
     *
     * @throws AiException a transient failure on a non-final attempt (the material goes back to `uploaded`)
     */
    public function handle(int $materialId, bool $final = true): void
    {
        $claimed = Material::query()->whereKey($materialId)
            ->whereIn('status', [MaterialStatus::Uploaded->value, MaterialStatus::Extracting->value])
            ->update([
                'status' => MaterialStatus::Extracting->value,
                'failure_reason' => null,
                'ingest_version' => DB::raw('ingest_version + 1'),
            ]);
        if ($claimed === 0) {
            return;
        }
        $material = Material::query()->findOrFail($materialId);

        try {
            $this->run($material);
        } catch (AiException $e) {
            if ($e->transient && ! $final) {
                $material->moveTo(MaterialStatus::Uploaded);
                throw $e;
            }
            $e->reason === FailureReason::SCANNED_NO_TEXT
                ? $material->moveTo(MaterialStatus::NeedsOcr, FailureReason::SCANNED_NO_TEXT)
                : $material->moveTo(MaterialStatus::Failed, $e->reason);
        }
    }

    private function run(Material $material): void
    {
        $diskName = (string) config('ai.disk');
        $disk = Storage::disk($diskName);
        if ($material->storage_path === null || ! $disk->exists($material->storage_path)) {
            throw new AiException(FailureReason::FILE_MISSING, false);
        }
        if (! $this->scanner->isClean($diskName, $material->storage_path)) {
            throw new AiException(FailureReason::INFECTED, false);
        }

        $stream = $disk->readStream($material->storage_path);
        try {
            $extracted = $this->ai->extract($stream, $material->original_name, $material->kind->value, $material->excel_mode->value);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $this->checkLimits($material, $extracted);

        $sectionIds = $this->saveOutline($material, $extracted);
        $payload = [];
        foreach ($extracted->sections as $i => $section) {
            $payload[] = ['id' => $sectionIds[$i], 'kind' => $section->kind, 'label' => $section->label, 'text' => $section->text];
        }
        $embedModel = (string) config('ai.materials.embed_model');
        $result = $this->ai->ingest($material->id, $material->ingest_version, $embedModel, $payload);

        DB::transaction(function () use ($material, $result, $sectionIds, $embedModel) {
            $known = array_flip($sectionIds);
            $now = now();
            $rows = [];
            foreach ($result->chunkRefs as $ref) {
                if (isset($known[$ref['section_id']])) { // never trust an id the service made up
                    $rows[] = ['material_id' => $material->id, 'section_id' => $ref['section_id'], 'chunk_ref' => $ref['chunk_ref'],
                        'ordinal' => $ref['ordinal'], 'char_count' => 0, 'created_at' => $now, 'updated_at' => $now];
                }
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                MaterialChunkRef::insert($chunk);
            }
            $material->embed_model = $embedModel;
            $material->moveTo(MaterialStatus::Ready);
        });

        event(new MaterialReady($material->id, $material->owner_user_id));
    }

    private function checkLimits(Material $material, ExtractedMaterial $extracted): void
    {
        if ($material->kind === MaterialKind::Pdf && $extracted->pages !== null && $extracted->pages > (int) config('ai.materials.max_pdf_pages')) {
            throw new AiException(FailureReason::TOO_MANY_PAGES, false);
        }
        if ($material->kind === MaterialKind::Xlsx && count($extracted->sections) > (int) config('ai.materials.max_xlsx_sheets')) {
            throw new AiException(FailureReason::TOO_MANY_SHEETS, false);
        }
        if ($extracted->sections === [] || $extracted->charCount === 0) {
            throw new AiException(FailureReason::NO_TEXT, false);
        }
    }

    /**
     * Replaces any outline from an earlier attempt.
     *
     * @return list<int> section ids, in the order of $extracted->sections
     */
    private function saveOutline(Material $material, ExtractedMaterial $extracted): array
    {
        return DB::transaction(function () use ($material, $extracted): array {
            MaterialChunkRef::query()->where('material_id', $material->id)->delete();
            MaterialSection::query()->where('material_id', $material->id)->delete();

            $ids = [];
            foreach ($extracted->sections as $section) {
                $ids[] = MaterialSection::create([
                    'material_id' => $material->id,
                    'kind' => $section->kind,
                    'label' => mb_substr($section->label, 0, 250),
                    'ordinal' => $section->ordinal,
                    'char_count' => $section->charCount(),
                ])->id;
            }

            $material->forceFill([
                'pages_count' => $material->kind === MaterialKind::Xlsx ? null : $extracted->pages,
                'sheets_count' => $material->kind === MaterialKind::Xlsx ? count($extracted->sections) : null,
                'char_count' => $extracted->charCount,
                'needs_text_review' => $extracted->needsTextReview,
            ])->save();

            return $ids;
        });
    }
}
