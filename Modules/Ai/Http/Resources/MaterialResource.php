<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Ai\Models\Material;
use Modules\Ai\Support\FailureReason;

/** @mixin Material */
class MaterialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'original_name' => $this->original_name,
            'kind' => $this->kind->value,
            'mime' => $this->mime,
            'size_bytes' => $this->size_bytes,
            'size_original' => $this->size_original ?? $this->size_bytes,
            'original_removed' => $this->original_removed,
            'status' => $this->status->value,
            'failure_reason' => $this->failure_reason,
            'failure_message' => FailureReason::message($this->failure_reason),
            'can_retry' => $this->failure_reason === FailureReason::AI_UNAVAILABLE && $this->status->value === 'failed',
            'pages_count' => $this->pages_count,
            'sheets_count' => $this->sheets_count,
            'char_count' => $this->char_count,
            'needs_text_review' => $this->needs_text_review,
            'excel_mode' => $this->excel_mode->value,
            'owner_user_id' => $this->owner_user_id,
            'owner' => $this->whenLoaded('owner', fn () => ['id' => $this->owner->id, 'name' => $this->owner->name]),
            'sections' => $this->whenLoaded('sections', fn () => $this->sections->map(fn ($s) => [
                'id' => $s->id, 'kind' => $s->kind, 'label' => $s->label, 'ordinal' => $s->ordinal,
                'char_count' => $s->char_count, 'excluded' => $s->excluded,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
