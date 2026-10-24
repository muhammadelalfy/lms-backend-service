<?php

declare(strict_types=1);

namespace Modules\Ai\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Ai\DTOs\UploadMaterialData;
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

    public function handle(User $actor, UploadMaterialData $data): Material
    {
        $file = $data->file;
        $inspected = $this->inspector->inspect($file);
        $this->entitlements->ensureCanStore((int) $file->getSize());

        $disk = Storage::disk((string) config('ai.disk'));
        $path = 'centers/'.tenant()->getTenantKey().'/ai-materials/'.Str::uuid();
        $stream = fopen((string) $file->getRealPath(), 'rb');
        try {
            $disk->writeStream($path, $stream);
        } finally {
            fclose($stream);
        }

        try {
            $material = DB::transaction(function () use ($actor, $data, $inspected, $path, $file): Material {
                $material = new Material([
                    'owner_user_id' => $actor->id,
                    'title' => $data->title ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                    'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                    'kind' => $inspected->kind,
                    'mime' => $inspected->mime,
                    'size_bytes' => (int) $file->getSize(),
                    'storage_path' => $path,
                    'sha256' => $inspected->sha256,
                    'excel_mode' => $data->excelMode,
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
