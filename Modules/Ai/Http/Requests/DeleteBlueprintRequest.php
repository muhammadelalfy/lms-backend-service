<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests;

/** Deleting stays possible after a plan downgrade. */
class DeleteBlueprintRequest extends BlueprintRequest
{
    protected string $ability = 'delete';

    protected function needsPlan(): bool
    {
        return false;
    }
}
