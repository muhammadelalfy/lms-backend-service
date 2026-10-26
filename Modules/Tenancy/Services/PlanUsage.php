<?php

declare(strict_types=1);

namespace Modules\Tenancy\Services;

use Modules\Auth\Models\User;
use Modules\Students\Models\Student;
use Modules\Tenancy\Exceptions\PlanLimitReached;
use Modules\Tenancy\Models\School;

/**
 * How much of its plan a center uses, read inside the center's own database. Only what can be counted
 * today is measured; the other caps answer null ("not measured yet"), never a guess.
 */
class PlanUsage
{
    /** Every cap the catalog knows, in the order screens show them. */
    public const CAPS = ['students', 'teachers', 'subjects', 'classes_per_subject', 'assistants', 'storage_gb'];

    /** @return array<string, int|null> cap => used, null when not measurable yet */
    public function of(School $school): array
    {
        return $school->run(fn () => [
            'students' => Student::query()->count(),
            'teachers' => $this->teachers(),
            'subjects' => null,            // needs the subject/class mapping (architect)
            'classes_per_subject' => null,
            'assistants' => null,          // the assistant role is not built yet (G8)
            'storage_gb' => null,          // no storage metering yet (E9)
        ]);
    }

    /**
     * Caps the center is over: used above the limit. Nothing is removed; new additions stay blocked.
     *
     * @param  array<string, int|null>|null  $usage
     * @return list<array{cap: string, limit: int, used: int}>
     */
    public function warnings(School $school, ?array $usage = null): array
    {
        $usage ??= $this->of($school);
        $warnings = [];
        foreach (self::CAPS as $cap) {
            $limit = $school->limit($cap);
            $used = $usage[$cap] ?? null;
            if ($limit !== null && $used !== null && $used > $limit) {
                $warnings[] = ['cap' => $cap, 'limit' => $limit, 'used' => $used];
            }
        }

        return $warnings;
    }

    /**
     * Refuses (402 plan_limit) one more teacher when the current center is at its teachers cap.
     * Outside a center (central context) there is no cap to apply.
     */
    public function ensureTeacherSeat(): void
    {
        $school = tenant();
        $limit = $school instanceof School ? $school->limit('teachers') : null;
        if ($limit === null) {
            return;
        }
        $used = $this->teachers();
        if ($used >= $limit) {
            throw new PlanLimitReached('teachers', $limit, $used);
        }
    }

    /** Teachers holding a seat: approved and not switched off. */
    private function teachers(): int
    {
        return User::query()->where('role', 'teacher')->where('status', User::STATUS_ACTIVE)->whereNull('disabled_at')->count();
    }
}
