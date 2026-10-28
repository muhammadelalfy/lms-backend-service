<?php

declare(strict_types=1);

namespace Modules\Ai\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Modules\Ai\Enums\ExcelMode;
use Modules\Ai\Exceptions\UploadProblem;
use Modules\Ai\Models\Material;
use Modules\Ai\Models\MaterialUpload;
use Modules\Auth\Models\User;

/** All pieces are here: check the whole file, turn it into a material and queue the extraction job. */
final class CompleteMaterialUpload
{
    public function __construct(private readonly UploadMaterial $upload) {}

    public function handle(User $actor, MaterialUpload $upload): Material
    {
        $upload->refresh();
        if (! $upload->isComplete()) {
            throw new UploadProblem('الرفع غير مكتمل بعد.', 'incomplete', 409, ['next_index' => $upload->next_index]);
        }
        // Whoever deletes the row first owns the completion; a second "complete" gets a 404.
        if (MaterialUpload::query()->whereKey($upload->id)->delete() !== 1) {
            throw new ModelNotFoundException;
        }

        $disk = Storage::disk((string) config('ai.disk'));
        try {
            if ($upload->sha256 !== null && ! hash_equals($upload->sha256, (string) hash_file('sha256', $disk->path($upload->temp_path)))) {
                throw new UploadProblem('الملف وصل تالفاً. أعد الرفع.', 'checksum_mismatch', 422);
            }

            return $this->upload->handleAssembled(
                $actor, $upload->temp_path, $upload->title, $upload->original_name, ExcelMode::tryFrom($upload->excel_mode) ?? ExcelMode::Auto,
            );
        } catch (\Throwable $e) {
            $disk->delete($upload->temp_path); // a refused file is not kept (a moved one is already gone)
            throw $e;
        }
    }
}
