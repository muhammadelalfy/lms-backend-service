<?php

namespace Modules\Tenancy\Support;

use Modules\Tenancy\Models\School;

/**
 * For requests that arrive on a central address, such as a webhook or an
 * OAuth redirect from another company, and carry the school inside them
 * (a room name, an encrypted state) instead of in the subdomain.
 */
final class SchoolContext
{
    /** Opens the school with this id for the rest of the request. */
    public static function enter(?string $id): School
    {
        $school = $id === null || $id === '' ? null : School::query()->find($id);
        abort_if($school === null, 404, 'المركز غير موجود.');
        abort_if(($reason = $school->lockedReason()) !== null, 403, $reason);

        tenancy()->initialize($school);

        return $school;
    }
}
