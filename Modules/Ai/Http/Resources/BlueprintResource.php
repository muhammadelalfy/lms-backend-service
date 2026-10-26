<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Ai\Models\ExamBlueprint;

/** @mixin ExamBlueprint */
class BlueprintResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'is_template' => $this->is_template,
            'is_private' => $this->is_private,
            'grade_level' => $this->grade_level,
            'subject' => $this->subject,
            'language' => $this->language,
            'outcomes' => array_values((array) $this->outcomes),
            'difficulty_mix' => $this->difficulty_mix,
            'bloom_mix' => $this->bloom_mix,
            'types' => $this->types(),
            'total_questions' => $this->totalQuestions(),
            'total_marks' => $this->total_marks,
            'duration_minutes' => $this->duration_minutes,
            'sources' => array_values((array) $this->source_selection),
            'instructions' => $this->instructions,
            'avoid_topics' => $this->avoid_topics,
            'owner_user_id' => $this->owner_user_id,
            'owner' => $this->whenLoaded('owner', fn () => ['id' => $this->owner->id, 'name' => $this->owner->name]),
            'can_edit' => $user !== null && $user->can('update', $this->resource),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
