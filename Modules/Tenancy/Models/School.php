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
 * @property string $plan trial | basic | pro | enterprise
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

    /** A trial that ran out behaves like a suspension until the school picks a plan. */
    public function trialHasEnded(): bool
    {
        return $this->plan === 'trial' && $this->trial_ends_at !== null && $this->trial_ends_at->isPast();
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
        return $this->max_students ?? config("schools.plans.{$this->plan}.max_students");
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
