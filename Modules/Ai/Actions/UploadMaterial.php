<?php

declare(strict_types=1);

namespace Modules\Ai\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Ai\DTOs\InspectedFile;
use Modules\Ai\DTOs\UploadMaterialData;
use Modules\Ai\Enums\ExcelMode;
use Modules\Ai\Enums\MaterialStatus;
use Modules\Ai\Events\MaterialUploaded;
use Modules\Ai\Jobs\ExtractMaterialJob;
use Modules\Ai\Models\Material;
use Modules\Ai\Services\AiEntitlements;
use Modules\Ai\Services\MaterialInspector;
use Modules\Auth\Models\User;

/** Checks the file by content, keeps it on the private disk under the center's prefix, then queues extraction. */
final class UploadMaterial
{
    public function __construct(private readonly MaterialInspector $inspector, private readonly AiEntitlements $entitlements) {}

    /** A file sent in one request (small files, older clients). */
    public function handle(User $actor, UploadMaterialData $data): Material
    {
        $file = $data->file;
        $inspected = $this->inspector->inspect($file);
        $this->entitlements->ensureCanStore($this->entitlements->expectedStoredBytes($inspected->kind->value, (int) $file->getSize()));

        $disk = Storage::disk((string) config('ai.disk'));
        $path = $this->newPath();
        $stream = fopen((string) $file->getRealPath(), 'rb');
        try {
            $disk->writeStream($path, $stream);
        } finally {
            fclose($stream);
        }

        return $this->register($actor, $data->title, $file->getClientOriginalName(), $inspected, $path, (int) $file->getSize(), $data->excelMode);
    }

    /**
     * A chunked upload that has arrived whole: the temp file on the private disk becomes the material's
     * file (a rename on the same disk, no copy) and the same checks and queueing apply.
     */
    public function handleAssembled(User $actor, string $stagedPath, ?string $title, string $originalName, ExcelMode $excelMode): Material
    {
        $disk = Storage::disk((string) config('ai.disk'));
        $inspected = $this->inspector->inspectPath($disk->path($stagedPath), $originalName);
        $size = (int) $disk->size($stagedPath);
        $this->entitlements->ensureCanStore($this->entitlements->expectedStoredBytes($inspected->kind->value, $size));

        $path = $this->newPath();
        $disk->move($stagedPath, $path);

        return $this->register($actor, $title, $originalName, $inspected, $path, $size, $excelMode);
    }

    private function newPath(): string
    {
        return 'centers/'.tenant()->getTenantKey().'/ai-materials/'.Str::uuid();
    }

    private function register(User $actor, ?string $title, string $originalName, InspectedFile $inspected, string $path, int $size, ExcelMode $excelMode): Material
    {
        $disk = Storage::disk((string) config('ai.disk'));
        try {
            $material = DB::transaction(function () use ($actor, $title, $originalName, $inspected, $path, $size, $excelMode): Material {
                $material = new Material([
                    'owner_user_id' => $actor->id,
                    'title' => $title ?: pathinfo($originalName, PATHINFO_FILENAME),
                    'original_name' => Str::limit($originalName, 250, ''),
                    'kind' => $inspected->kind,
                    'mime' => $inspected->mime,
                    'size_bytes' => $size,
                    'size_original' => $size,
                    'storage_path' => $path,
                    'sha256' => $inspected->sha256,
                    'excel_mode' => $excelMode,
                ]);
                $material->status = MaterialStatus::Uploaded;
                $material->save();

                return $material;
            });
        } catch (\Throwable $e) {
            $disk->delete($path);
            throw $e;
        }

        // Only now that the row is committed: a worker must find it.
        event(new MaterialUploaded($material->id, $actor->id));
        ExtractMaterialJob::dispatch($material->id);

        return $material;
    }
}
