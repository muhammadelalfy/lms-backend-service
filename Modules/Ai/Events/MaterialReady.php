<?php

declare(strict_types=1);

namespace Modules\Ai\Events;

final class MaterialReady
{
    public function __construct(public readonly int $materialId, public readonly int $ownerId) {}
}
