<?php

declare(strict_types=1);

namespace Modules\Ai\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A chunked upload in progress: the pieces are appended to one temp file on the private disk. */
class MaterialUpload extends Model
{
    use HasUuids;

    protected $table = 'ai_material_uploads';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'chunk_bytes' => 'integer',
            'total_chunks' => 'integer',
            'next_index' => 'integer',
            'received_bytes' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function isComplete(): bool
    {
        return $this->next_index >= $this->total_chunks && $this->received_bytes === $this->size_bytes;
    }
}
