<?php

declare(strict_types=1);

namespace Modules\Ai\Actions;

use Modules\Ai\Enums\MaterialStatus;
use Modules\Ai\Jobs\ExtractMaterialJob;
use Modules\Ai\Models\Material;

/** Sends a failed material through extraction again. Returns false when it is not in a state that can be retried. */
final class RetryMaterial
{
    public function handle(Material $material): bool
    {
        $moved = Material::query()->whereKey($material->id)->where('status', MaterialStatus::Failed->value)
            ->update(['status' => MaterialStatus::Uploaded->value, 'failure_reason' => null]);
        if ($moved === 0) {
            return false;
        }
        ExtractMaterialJob::dispatch($material->id);

        return true;
    }
}
