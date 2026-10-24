<?php

declare(strict_types=1);

namespace Modules\Courses\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Modules\Auth\Models\User;
use Modules\Courses\Enums\ContentStatus;
use Modules\Courses\Enums\CourseKind;
use Modules\Courses\Enums\OutlineStyle;
use Modules\Courses\Enums\Pricing;
use Modules\Courses\Enums\SequencingMode;
use Modules\Courses\Exceptions\ContentRuleViolation;
use Modules\Courses\Models\Concerns\HasPublicationLifecycle;

class Course extends Model
{
    use HasPublicationLifecycle;

    protected $fillable = [
        'kind', 'title', 'slug', 'summary', 'description', 'goals', 'target_audience', 'estimated_duration_minutes',
        'starts_at', 'ends_at', 'visible_from', 'visible_until', 'is_featured', 'season_name',
        'sequencing_mode', 'outline_style', 'pricing', 'price_amount', 'discounted_price_amount', 'access_days', 'created_by',
    ];

    protected $attributes = [
        'kind' => 'course',
        'status' => 'draft',
        'pricing' => 'free',
        'sequencing_mode' => 'open',
        'outline_style' => 'subjects',
        'is_featured' => false,
        'version' => 1,
    ];

    protected function casts(): array
    {
        return [
            'kind' => CourseKind::class,
            'status' => ContentStatus::class,
            'pricing' => Pricing::class,
            'sequencing_mode' => SequencingMode::class,
            'outline_style' => OutlineStyle::class,
            'goals' => 'array',
            'is_featured' => 'boolean',
            'estimated_duration_minutes' => 'integer',
            'price_amount' => 'integer',
            'discounted_price_amount' => 'integer',
            'access_days' => 'integer',
            'version' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'visible_from' => 'datetime',
            'visible_until' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(CourseSubject::class)->orderBy('position')->orderBy('id');
    }

    public function lessons(): HasManyThrough
    {
        return $this->hasManyThrough(CourseLesson::class, CourseSubject::class, 'course_id', 'subject_id');
    }

    public function instructors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'course_instructors')->withPivot('position')->orderByPivot('position');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CourseVersion::class)->orderByDesc('number');
    }

    public function accessCodes(): HasMany
    {
        return $this->hasMany(AccessCode::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function isTaughtBy(User $user): bool
    {
        return $this->instructors()->whereKey($user->id)->exists();
    }

    /** What the public catalog may list: published, and inside its visibility window. */
    public function scopePubliclyVisible(Builder $query): void
    {
        $now = now();
        $query->where('status', ContentStatus::Published)
            ->where(fn (Builder $q) => $q->whereNull('visible_from')->orWhere('visible_from', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('visible_until')->orWhere('visible_until', '>', $now));
    }

    public function assertPublishable(): void
    {
        $hasPublishedContent = $this->subjects()
            ->where('status', ContentStatus::Published)
            ->whereHas('lessons', fn (Builder $lessons) => $lessons->where('status', ContentStatus::Published))
            ->exists();

        if (! $hasPublishedContent) {
            throw ContentRuleViolation::courseWithoutPublishedContent();
        }
    }

    /** What a student pays, or null when the course is free. */
    public function finalPrice(): ?int
    {
        return $this->pricing === Pricing::Free ? null : ($this->discounted_price_amount ?? $this->price_amount);
    }

    /** Whether students already in the course may still use it (a hidden course keeps its students; an archived one is closed). */
    public function isOpenToStudents(): bool
    {
        return in_array($this->status, [ContentStatus::Published, ContentStatus::Hidden], true);
    }
}
