<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Modules\Ai\Contracts\MaterialScanner;

/** Placeholder until ClamAV (or another scanner) is wired through config('ai.scanner'). */
final class NullMaterialScanner implements MaterialScanner
{
    public function isClean(string $disk, string $path): bool
    {
        return true;
    }
}
