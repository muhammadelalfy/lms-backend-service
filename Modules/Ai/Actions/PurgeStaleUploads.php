<?php

declare(strict_types=1);

namespace Modules\Ai\Actions;

use Illuminate\Support\Facades\Storage;
use Modules\Ai\Models\MaterialUpload;

/** Removes abandoned chunked uploads (expired, never completed) together with their temp files. */
final class PurgeStaleUploads
{
    public function handle(): int
    {
        $disk = Storage::disk((string) config('ai.disk'));
        $count = 0;
        MaterialUpload::query()->where('expires_at', '<', now())->each(function (MaterialUpload $upload) use ($disk, &$count) {
            $disk->delete($upload->temp_path);
            $upload->delete();
            $count++;
        });

        return $count;
    }
}
