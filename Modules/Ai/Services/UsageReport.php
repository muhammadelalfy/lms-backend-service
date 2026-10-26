<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\Ai\Enums\UsageKind;
use Modules\Ai\Models\DraftExam;
use Modules\Ai\Models\UsageEntry;
use Modules\Auth\Models\User;

/**
 * The usage screen: the center's units this month against the cap (with the 80% warning and the 100%
 * block), and the breakdown by feature, by exam and per day for the last 30 days. The admin sees the
 * whole center; a teacher sees the center's totals (the cap is shared) and their own breakdown.
 * A fixed number of aggregate queries, whatever the volume.
 */
final class UsageReport
{
    public function __construct(private readonly UnitBudget $budget) {}

    /** @return array<string, mixed> */
    public function for(User $user): array
    {
        $budget = $this->budget->snapshot();
        $month = $this->budget->month();
        $own = ! $user->isAnyRole('admin');
        $scoped = fn (): Builder => UsageEntry::query()->when($own, fn ($q) => $q->where('user_id', $user->id));

        $byKind = $scoped()->where('month', $month)->selectRaw('kind, sum(units) as units')->groupBy('kind')->toBase()->pluck('units', 'kind');
        $breakdown = [];
        foreach (UsageKind::cases() as $kind) {
            $breakdown[$kind->value] = (int) ($byKind[$kind->value] ?? 0);
        }

        $exams = $scoped()->where('month', $month)->whereNotNull('draft_id')
            ->selectRaw('draft_id, sum(units) as units')->groupBy('draft_id')->orderByDesc('units')->limit(20)->toBase()->get();
        $titles = DraftExam::query()->whereIn('id', $exams->pluck('draft_id'))->pluck('title', 'id');

        $from = now()->startOfDay()->subDays(29);
        $perDay = $scoped()->where('created_at', '>=', $from)
            ->selectRaw('date(created_at) as day, sum(units) as units')->groupByRaw('date(created_at)')->toBase()->pluck('units', 'day');
        $daily = [];
        for ($i = 0; $i < 30; $i++) {
            $day = $from->copy()->addDays($i)->toDateString();
            $daily[] = ['date' => $day, 'units' => (int) ($perDay[$day] ?? 0)];
        }

        return [
            'month' => $month,
            'resets_at' => now()->startOfMonth()->addMonth()->toIso8601String(),
            'cap_units' => $budget['cap'],
            'used_units' => $budget['used'],
            'reserved_units' => $budget['reserved'],
            'remaining_units' => $budget['remaining'],
            'percent' => $budget['percent'],
            'warning' => $budget['warning'],
            'blocked' => $budget['blocked'],
            'message' => match (true) {
                $budget['blocked'] => 'بلغ مركزك حد وحدات الذكاء الاصطناعي لهذا الشهر. يمكنك إنشاء الامتحانات يدوياً أو ترقية الباقة.',
                $budget['warning'] => 'استهلك مركزك '.(int) $budget['percent'].'٪ من وحدات الذكاء الاصطناعي لهذا الشهر.',
                default => null,
            },
            'scope' => $own ? 'mine' : 'center',
            'mine_units' => (int) UsageEntry::query()->where('month', $month)->where('user_id', $user->id)->sum('units'),
            'breakdown' => $breakdown,
            'by_exam' => $exams->map(fn ($row) => [
                'draft_id' => (int) $row->draft_id, 'title' => $titles[$row->draft_id] ?? null, 'units' => (int) $row->units,
            ])->values()->all(),
            'daily' => $daily,
        ];
    }
}
