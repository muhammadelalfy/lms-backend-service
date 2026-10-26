<?php

declare(strict_types=1);

namespace Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A request for a plan, kept in the central database so the operator sees every center's in one inbox:
 * a custom-plan lead from the landing, or an upgrade asked for by a center's admin.
 *
 * @property int $id
 * @property string $type custom | upgrade | lead
 * @property string|null $audience solo | group | center
 * @property string $name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $org_name
 * @property int|null $students_estimate
 * @property string|null $plan_slug
 * @property string $message
 * @property string|null $school_id
 * @property string $status new | contacted | closed
 * @property Carbon $created_at
 */
class PlanRequest extends Model
{
    use CentralConnection;

    public const TYPES = ['custom', 'upgrade', 'lead'];

    public const STATUSES = ['new', 'contacted', 'closed'];

    protected $fillable = [
        'type', 'audience', 'name', 'phone', 'email', 'org_name', 'students_estimate', 'plan_slug',
        'message', 'school_id', 'status', 'ip_hash', 'user_agent',
    ];

    protected $attributes = ['status' => 'new'];

    protected function casts(): array
    {
        return ['students_estimate' => 'integer'];
    }

    /** @param  Builder<self>  $query */
    public function scopeRecent(Builder $query, int $hours = 24): Builder
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }
}
