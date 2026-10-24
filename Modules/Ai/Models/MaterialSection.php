<?php

declare(strict_types=1);

namespace Modules\Ai\Models;

use Illuminate\Database\Eloquent\Model;

class MaterialSection extends Model
{
    protected $table = 'ai_material_sections';

    protected $fillable = ['material_id', 'kind', 'label', 'ordinal', 'char_count', 'excluded'];

    protected function casts(): array
    {
        return ['ordinal' => 'integer', 'char_count' => 'integer', 'excluded' => 'boolean'];
    }
}
