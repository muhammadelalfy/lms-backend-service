<?php

declare(strict_types=1);

namespace Modules\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Ai\Enums\ExcelMode;
use Modules\Ai\Enums\MaterialKind;
use Modules\Ai\Enums\MaterialStatus;
use Modules\Auth\Models\User;

/** A teaching file (PDF, Word, Excel) a teacher uploaded for AI exams. Private to the center. */
class Material extends Model
{
    use SoftDeletes;

    protected $table = 'ai_materials';

    protected $fillable = [
        'owner_user_id', 'title', 'original_name', 'kind', 'mime', 'size_bytes', 'storage_path', 'sha256',
        'excel_mode', 'embed_model', 'size_original',
    ];

    protected function casts(): array
    {
        return [
            'kind' => MaterialKind::class,
            'status' => MaterialStatus::class,
            'excel_mode' => ExcelMode::class,
            'size_bytes' => 'integer',
            'pages_count' => 'integer',
            'sheets_count' => 'integer',
            'char_count' => 'integer',
            'ingest_version' => 'integer',
            'size_original' => 'integer',
            'original_removed' => 'boolean',
            'needs_text_review' => 'boolean',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(MaterialSection::class)->orderBy('ordinal');
    }

    public function chunkRefs(): HasMany
    {
        return $this->hasMany(MaterialChunkRef::class);
    }

    /** Moves along the status machine; an impossible move is a bug, not a user error. */
    public function moveTo(MaterialStatus $target, ?string $failureReason = null): void
    {
        if (! $this->status->canBecome($target)) {
            throw new \LogicException("A material cannot go from {$this->status->value} to {$target->value}.");
        }
        $this->status = $target;
        $this->failure_reason = $failureReason;
        $this->save();
    }
}
