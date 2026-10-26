<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests;

class DeleteMaterialRequest extends MaterialRequest
{
    protected function needsPlan(): bool
    {
        return false;
    }
}
