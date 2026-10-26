<?php

declare(strict_types=1);

namespace Modules\Ai\Models;

use Illuminate\Database\Eloquent\Model;

class MaterialChunkRef extends Model
{
    protected $table = 'ai_material_chunk_refs';

    protected $fillable = ['material_id', 'section_id', 'chunk_ref', 'ordinal', 'char_count'];
}
