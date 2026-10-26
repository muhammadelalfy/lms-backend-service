<?php

declare(strict_types=1);

namespace Modules\Tenancy\Services;

use Illuminate\Support\Facades\Cache;
use Modules\Tenancy\Models\School;
use Modules\Tenancy\Support\Contrast;

/**
 * How a center looks (name, logo, colors), as the sign-in page, the app shell and the mobile app read
 * it. The public read is cached per center (the cache is center-scoped) and cleared on every change.
 */
class Branding
{
    public const CACHE_SECONDS = 300;

    private const CACHE_KEY = 'branding.v1';

    /** The disk logos live on: the shared public storage, served at /storage, under tenants/{id}/branding. */
    public const DISK = 'branding';

    public function cached(School $school): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => $this->present($school));
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array{name: string, brand_color: string|null, primary_color: string|null, secondary_color: string|null,
     *     accent_text_color: string|null, logo_url: string|null, updated_at: string|null}
     */
    public function present(School $school): array
    {
        $primary = $school->brand_color;

        return [
            'name' => $school->name,
            // `brand_color` is the original field; `primary_color` is the same value under the spec's name.
            'brand_color' => $primary,
            'primary_color' => $primary,
            'secondary_color' => $school->secondary_color,
            'accent_text_color' => $primary === null ? null : Contrast::textOn($primary),
            'logo_url' => $school->logo_url,
            'updated_at' => $school->updated_at?->toIso8601String(),
        ];
    }

    /** @return list<array{code: string, ratio: float}> saved anyway; shown beside the color picker */
    public function warnings(School $school): array
    {
        $primary = $school->brand_color;
        if ($primary === null) {
            return [];
        }
        $ratio = Contrast::ratio('#FFFFFF', $primary);

        return $ratio < Contrast::AA ? [['code' => 'low_contrast', 'ratio' => round($ratio, 2)]] : [];
    }

    /** Where a center's logo is kept on the branding disk. */
    public static function directory(School $school): string
    {
        return 'tenants/'.$school->getTenantKey().'/branding';
    }
}
