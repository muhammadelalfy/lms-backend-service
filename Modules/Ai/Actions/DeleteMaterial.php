<?php

declare(strict_types=1);

namespace Modules\Ai\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Ai\Jobs\PurgeMaterialJob;
use Modules\Ai\Models\Material;
use Modules\Ai\Models\MaterialChunkRef;
use Modules\Ai\Models\MaterialSection;

/** Deletes the file and the outline at once, and queues the AI service's vectors for deletion. */
final class DeleteMaterial
{
    public function handle(Material $material): void
    {
        $path = $material->storage_path;

        DB::transaction(function () use ($material) {
            MaterialChunkRef::query()->where('material_id', $material->id)->delete();
            MaterialSection::query()->where('material_id', $material->id)->delete();
            $material->storage_path = null;
            $material->save();
            $material->delete();
        });
        PurgeMaterialJob::dispatch($material->id); // after the commit, so the job never runs ahead of the delete

        if ($path !== null) {
            Storage::disk((string) config('ai.disk'))->delete($path);
        }
    }
}
