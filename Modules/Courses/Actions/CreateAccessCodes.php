<?php

declare(strict_types=1);

namespace Modules\Courses\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Modules\Courses\Models\AccessCode;
use Modules\Courses\Models\Course;

/** Makes a batch of codes for a course: one insert, however many are asked for. */
final class CreateAccessCodes
{
    /** No 0/O/1/I/L: a code is read aloud and copied by hand. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const LENGTH = 8;

    /** @return Collection<int, AccessCode> */
    public function handle(Course $course, int $count, int $maxUses, ?CarbonInterface $expiresAt, int $createdBy): Collection
    {
        $codes = $this->fresh($count);
        $now = now();

        AccessCode::query()->insert($codes->map(fn (string $code) => [
            'course_id' => $course->id,
            'code' => $code,
            'max_uses' => $maxUses,
            'used_count' => 0,
            'expires_at' => $expiresAt,
            'created_by' => $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());

        return AccessCode::query()->whereIn('code', $codes)->orderBy('id')->get();
    }

    /** @return Collection<int, string> codes that are unique among themselves and not yet in use */
    private function fresh(int $count): Collection
    {
        $codes = collect();
        while ($codes->count() < $count) {
            $batch = collect(range(1, $count - $codes->count()))->map(fn () => $this->random());
            $taken = AccessCode::query()->whereIn('code', $batch)->pluck('code');
            $codes = $codes->merge($batch->diff($taken))->unique()->values();
        }

        return $codes;
    }

    private function random(): string
    {
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }
}
