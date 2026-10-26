<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Ai\Enums\JobStatus;
use Modules\Ai\Enums\UsageKind;
use Modules\Ai\Exceptions\CapReached;
use Modules\Ai\Models\GenerationJob;
use Modules\Ai\Models\UsageEntry;
use Modules\Tenancy\Models\School;

/**
 * The center's monthly AI units (plan cap `ai_units_monthly`, null = unlimited), counted in this app:
 * spent = the ledger of the month, held = the reservations of jobs still running. A job reserves its
 * estimate before it starts (refused when it does not fit) and never spends past its reservation, so
 * spent + held never passes the cap however jobs and retries interleave. The month is the calendar
 * month of the app's timezone.
 */
final class UnitBudget
{
    public function month(): string
    {
        return now()->format('Y-m');
    }

    public function cap(): ?int
    {
        return $this->school()->limit('ai_units_monthly');
    }

    public function spent(): int
    {
        return (int) UsageEntry::query()->where('month', $this->month())->sum('units');
    }

    public function held(): int
    {
        return (int) GenerationJob::query()->whereIn('status', JobStatus::activeValues())->sum('reserved_units');
    }

    /** @return array{cap: int|null, used: int, reserved: int, remaining: int|null, percent: float|null, warning: bool, blocked: bool} */
    public function snapshot(): array
    {
        $cap = $this->cap();
        $used = $this->spent();
        $held = $this->held();
        $remaining = $cap === null ? null : max(0, $cap - $used - $held);
        $percent = $cap === null ? null : ($cap === 0 ? 100.0 : round(($used + $held) * 100 / $cap, 1));

        return [
            'cap' => $cap,
            'used' => $used,
            'reserved' => $held,
            'remaining' => $remaining,
            'percent' => $percent,
            'warning' => $percent !== null && $percent >= (float) config('ai.units.warning_percent'),
            'blocked' => $remaining !== null && $remaining <= 0,
        ];
    }

    /** Whether work of this many units fits in what is left of the month. */
    public function fits(int $units): bool
    {
        $remaining = $this->snapshot()['remaining'];

        return $remaining === null || ($remaining > 0 && $units <= $remaining);
    }

    /**
     * Reserves units for new work and creates it, atomically for the center: two starts at once cannot
     * both take the last units. `$create` receives the units held and must persist them (its job row).
     *
     * @template T
     *
     * @param  callable(int): T  $create
     * @return T
     *
     * @throws CapReached when the estimate does not fit
     */
    public function reserve(int $estimate, callable $create): mixed
    {
        return Cache::lock('ai-units:'.tenant()->getTenantKey(), 15)->block(10, function () use ($estimate, $create) {
            $remaining = $this->snapshot()['remaining'];
            if ($remaining !== null && ($remaining <= 0 || $estimate > $remaining)) {
                throw self::capReached();
            }
            $held = (int) ceil(max(1, $estimate) * (float) config('ai.units.reserve_margin'));
            if ($remaining !== null) {
                $held = min($held, $remaining);
            }

            return DB::transaction(fn () => $create($held));
        });
    }

    /**
     * Records the units of one AI call and takes them off the job's reservation. Idempotent: the same
     * `callKey` is charged once, so a retried job never pays twice for the same call.
     *
     * @return bool false when that call was already charged
     */
    public function charge(GenerationJob $job, UsageKind $kind, string $callKey, int $units, string $model, string $promptVersion): bool
    {
        $inserted = UsageEntry::query()->insertOrIgnore([
            'kind' => $kind->value,
            'job_id' => $job->id,
            'draft_id' => $job->draft_id,
            'user_id' => $job->requested_by,
            'units' => $units,
            'model_id' => mb_substr($model, 0, 120),
            'prompt_version' => mb_substr($promptVersion, 0, 40),
            'month' => $this->month(),
            'call_key' => $callKey,
            'created_at' => now(),
        ]);
        if ($inserted === 0) {
            return false;
        }
        GenerationJob::query()->whereKey($job->id)->update([
            'units_used' => DB::raw('units_used + '.$units),
            'reserved_units' => DB::raw('CASE WHEN reserved_units > '.$units.' THEN reserved_units - '.$units.' ELSE 0 END'),
        ]);
        $job->units_used += $units;
        $job->reserved_units = max(0, $job->reserved_units - $units);

        return true;
    }

    /**
     * Grading's hard stop: already-queued answers keep being graded past 100% of the cap (so a class
     * that just submitted is not half graded), until the month's spending reaches the cap plus
     * `ai.grading.hard_stop_percent` of it.
     */
    public function gradingStopped(): bool
    {
        $cap = $this->cap();
        if ($cap === null) {
            return false;
        }

        return $this->spent() >= $cap + (int) ceil($cap * (int) config('ai.grading.hard_stop_percent') / 100);
    }

    /**
     * Records the units of one grading call. Idempotent by `callKey` (one charge per answer, whatever
     * the retries).
     */
    public function chargeGrading(string $callKey, ?int $userId, int $units, string $model, string $promptVersion): bool
    {
        return UsageEntry::query()->insertOrIgnore([
            'kind' => UsageKind::Grade->value,
            'job_id' => null,
            'draft_id' => null,
            'user_id' => $userId,
            'units' => $units,
            'model_id' => mb_substr($model, 0, 120),
            'prompt_version' => mb_substr($promptVersion, 0, 40),
            'month' => $this->month(),
            'call_key' => $callKey,
            'created_at' => now(),
        ]) > 0;
    }

    public static function capReached(): CapReached
    {
        return new CapReached('ai_units_monthly', 'بلغ مركزك حد وحدات الذكاء الاصطناعي لهذا الشهر. يمكنك إنشاء الامتحان يدوياً أو ترقية الباقة.');
    }

    private function school(): School
    {
        $school = tenant();
        if (! $school instanceof School) {
            throw new \LogicException('AI units are only counted inside a school.');
        }

        return $school;
    }
}
