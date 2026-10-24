<?php

namespace Modules\Tenancy\Models;

use Illuminate\Support\Carbon;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * A school: one customer of the platform, with its own database, its own
 * subdomain, a plan and a status. Lives in the central database.
 *
 * @property string $id the subdomain, e.g. "alnour"
 * @property string $name
 * @property string $plan t1..t8, or a legacy plan: trial | basic | pro | enterprise
 * @property string $status active | suspended
 * @property Carbon|null $trial_ends_at
 * @property int|null $max_students
 * @property string|null $brand_color the academy's color, like #1F4E8C (kept in `data`)
 * @property string|null $logo_url (kept in `data`)
 * @property string|null $kind center | group | solo, as the owner described themselves at sign-up (kept in `data`)
 */
class School extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    // The id is the subdomain the operator chooses, never a generated number.
    public function getIncrementing()
    {
        return false;
    }

    public function shouldGenerateId(): bool
    {
        return false;
    }

    public function getKeyType()
    {
        return 'string';
    }

    /** Columns of the central table; every other attribute is kept in its `data` JSON. */
    public static function getCustomColumns(): array
    {
        return ['id', 'name', 'plan', 'status', 'trial_ends_at', 'max_students'];
    }

    protected function casts(): array
    {
        return ['trial_ends_at' => 'datetime', 'max_students' => 'integer'];
    }

    /** Whether people of this school may use the app now. */
    public function isActive(): bool
    {
        return $this->status === self::ACTIVE && ! $this->trialHasEnded();
    }

    /** A trial (any school with an end date) that ran out behaves like a suspension until a plan is chosen. A paid school has no end date. */
    public function trialHasEnded(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isPast();
    }

    /** Why the school cannot be used, in words for the person, or null when it can. */
    public function lockedReason(): ?string
    {
        if ($this->status !== self::ACTIVE) {
            return 'تم إيقاف حساب المركز. تواصل مع إدارة المنصة.';
        }

        return $this->trialHasEnded() ? 'انتهت الفترة التجريبية للمركز. اختر باقة للمتابعة.' : null;
    }

    /** How many students the school may have: its own limit, else its plan's; null = unlimited. */
    public function studentLimit(): ?int
    {
        return $this->limit('students');
    }

    /**
     * One cap of the school, null = unlimited. Order: the `max_students` column (students only), the
     * operator's override in `data.limits`, then the plan's own limit.
     */
    public function limit(string $key): ?int
    {
        if ($key === 'students' && $this->max_students !== null) {
            return $this->max_students;
        }
        $overrides = (array) ($this->getAttributes()['limits'] ?? []);
        if (array_key_exists($key, $overrides)) {
            return $overrides[$key] === null ? null : (int) $overrides[$key];
        }
        $value = config("schools.plans.{$this->plan}.limits.{$key}");

        return $value === null ? null : (int) $value;
    }

    /**
     * A plan feature of this school: true | false | 'soon'. The operator can switch one on (or off) for a
     * single school through `data.features`, otherwise the plan decides; unknown features are false.
     */
    public function feature(string $key): bool|string
    {
        $overrides = (array) ($this->getAttributes()['features'] ?? []);
        $value = array_key_exists($key, $overrides) ? $overrides[$key] : config("schools.plans.{$this->plan}.features.{$key}", false);

        return $value === 'soon' ? 'soon' : (bool) $value;
    }

    /** @return array<string, int|null> every cap of the school */
    public function limits(): array
    {
        $keys = array_unique(array_merge(
            array_keys((array) config("schools.plans.{$this->plan}.limits")),
            array_keys((array) ($this->getAttributes()['limits'] ?? [])),
        ));

        return collect($keys)->mapWithKeys(fn (string $key) => [$key => $this->limit($key)])->all();
    }

    /** The settings the school may override (payment, SMS, WhatsApp). */
    public function settings(): array
    {
        return array_filter(
            array_intersect_key($this->getAttributes(), config('schools.settings')),
            fn ($value) => $value !== null,
        );
    }
}
