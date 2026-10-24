<?php

declare(strict_types=1);

namespace Modules\Courses\Support;

use Illuminate\Support\Carbon;

final class SchoolTime
{
    /**
     * Times arrive with their own offset (a browser sends UTC), but the database holds wall-clock times in
     * the application's timezone. Converting first keeps the instant the same; otherwise an 18:00 session
     * would be read back hours earlier or later.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public static function convert(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            if (! empty($data[$key])) {
                $data[$key] = Carbon::parse($data[$key])->setTimezone(config('app.timezone'))->toDateTimeString();
            }
        }

        return $data;
    }
}
