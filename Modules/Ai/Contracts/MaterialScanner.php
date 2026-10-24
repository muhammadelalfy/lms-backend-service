<?php

declare(strict_types=1);

namespace Modules\Ai\Contracts;

/** The antivirus hook: runs before a file is sent for extraction. */
interface MaterialScanner
{
    /** True when the stored file is clean; false when it must be rejected. */
    public function isClean(string $disk, string $path): bool;
}
