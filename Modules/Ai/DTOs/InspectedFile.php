<?php

declare(strict_types=1);

namespace Modules\Ai\DTOs;

use Modules\Ai\Enums\MaterialKind;

final readonly class InspectedFile
{
    public function __construct(public MaterialKind $kind, public string $mime, public string $sha256) {}
}
