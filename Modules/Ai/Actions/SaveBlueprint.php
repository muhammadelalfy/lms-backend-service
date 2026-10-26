<?php

declare(strict_types=1);

namespace Modules\Ai\Actions;

use Modules\Ai\DTOs\BlueprintData;
use Modules\Ai\Models\ExamBlueprint;
use Modules\Auth\Models\User;

/** Creates or edits a blueprint. A job keeps its own snapshot, so editing never changes a running or past job. */
final class SaveBlueprint
{
    public function create(User $actor, BlueprintData $data): ExamBlueprint
    {
        return ExamBlueprint::create($data->forCreate() + ['owner_user_id' => $actor->id]);
    }

    public function update(ExamBlueprint $blueprint, BlueprintData $data): ExamBlueprint
    {
        $blueprint->fill($data->attributes)->save();

        return $blueprint;
    }
}
