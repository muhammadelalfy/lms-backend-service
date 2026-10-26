<?php

declare(strict_types=1);

namespace Modules\Tenancy\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Reads the plan catalog in config/schools.php and derives what is never stored: the shown
 * (promo) and yearly prices. Landing, sign-up, limits and /api/plans all go through here,
 * so a price edited in config cannot drift anywhere.
 */
class PlanCatalog
{
    public const CACHE_SECONDS = 600;

    /** The key of the cached public catalog; changes whenever the config changes. */
    public static function cacheKey(): string
    {
        return 'plans.catalog.'.md5(json_encode(config('schools')));
    }

    /** The public catalog, cached for [CACHE_SECONDS]. */
    public function cached(): array
    {
        return Cache::remember(self::cacheKey(), self::CACHE_SECONDS, fn () => $this->toArray());
    }

    public function toArray(): array
    {
        $pricing = config('schools.pricing');

        return [
            'currency' => $pricing['currency'],
            'trial_days' => (int) config('schools.trial_days'),
            'vat_included' => (bool) $pricing['vat_included'],
            'yearly_months_billed' => (int) $pricing['yearly_months_billed'],
            'promo' => $this->promo(),
            'addons' => [
                'extra_student' => $this->addon((float) $pricing['addons']['extra_student']),
                'extra_storage_gb' => $this->addon((float) $pricing['addons']['extra_storage_gb']),
            ],
            'audiences' => array_values($this->audiences()),
            'plans' => array_values($this->publicPlans()),
            'feature_rows' => $pricing['feature_rows'],
        ];
    }

    /** @return array{percent: int, label: string, active: bool, ends_at: string|null} */
    public function promo(): array
    {
        $percent = (int) config('schools.pricing.promo_percent');
        $endsAt = config('schools.pricing.promo_ends_at');
        $ended = $endsAt !== null && now()->greaterThan(now()->parse($endsAt)->endOfDay());

        return [
            'percent' => $percent,
            'label' => (string) config('schools.pricing.promo_label'),
            'active' => $percent > 0 && ! $ended,
            'ends_at' => $endsAt === null ? null : now()->parse($endsAt)->toDateString(),
        ];
    }

    /** @return array<string, array> slug => plan, public plans only, cheapest first */
    public function publicPlans(): array
    {
        $plans = [];
        foreach (config('schools.plans') as $slug => $plan) {
            if ($plan['public'] ?? false) {
                $plans[$slug] = $this->present($slug, $plan);
            }
        }
        uasort($plans, fn (array $a, array $b) => $a['tier'] <=> $b['tier']);

        return $plans;
    }

    /** A public plan, or null for an unknown or legacy slug. */
    public function plan(string $slug): ?array
    {
        return $this->publicPlans()[$slug] ?? null;
    }

    /** @return string[] */
    public function publicSlugs(): array
    {
        return array_keys($this->publicPlans());
    }

    /** @return array<string, array> key => audience tab, in the order of the config */
    public function audiences(): array
    {
        $audiences = [];
        foreach (config('schools.pricing.audiences') as $key => $audience) {
            $audiences[$key] = ['key' => $key] + $audience;
        }

        return $audiences;
    }

    /** The plan a trial of [$kind] starts on when the visitor picked none. */
    public function trialPlanFor(string $kind): string
    {
        foreach ($this->audiences() as $audience) {
            if ($audience['kind'] === $kind) {
                return $audience['trial_plan'];
            }
        }

        return (string) config('schools.default_plan');
    }

    /** The plan a trial really gets: the chosen one, but never above `trial_max_plan`. */
    public function effectiveTrialPlan(string $chosen): string
    {
        $max = (string) config('schools.trial_max_plan');

        return $this->tier($chosen) <= $this->tier($max) ? $chosen : $max;
    }

    /**
     * The plan to offer a center on [$slug]: the next public tier, or for a legacy plan the first public
     * plan that holds more students. Null at the top (or on an unlimited legacy plan).
     */
    public function nextPlan(string $slug, ?int $studentLimit = null): ?array
    {
        $tier = $this->tier($slug);
        foreach ($this->publicPlans() as $plan) {
            $fits = $tier > 0
                ? $plan['tier'] > $tier
                : $studentLimit !== null && ($plan['limits']['students'] === null || $plan['limits']['students'] > $studentLimit);
            if ($fits) {
                return $plan;
            }
        }

        return null;
    }

    /** The name of any catalog plan, legacy ones included; the slug itself when unknown. */
    public function label(string $slug): string
    {
        return (string) config("schools.plans.{$slug}.label", $slug);
    }

    public function tier(string $slug): int
    {
        return (int) config("schools.plans.{$slug}.tier", 0);
    }

    private function present(string $slug, array $plan): array
    {
        $list = (int) $plan['list_monthly'];
        $promo = $this->promo();
        $shown = $promo['active'] ? $this->discount($list, $promo['percent']) : $list;
        $yearly = $shown * (int) config('schools.pricing.yearly_months_billed');

        return [
            'slug' => $slug,
            'tier' => (int) $plan['tier'],
            'name' => $plan['label'],
            'limits' => $plan['limits'],
            'price' => [
                'list_monthly' => $list,
                'shown_monthly' => $shown,
                'shown_yearly' => $yearly,
                'yearly_per_month' => $yearly % 12 === 0 ? intdiv($yearly, 12) : round($yearly / 12, 2),
            ],
            'features' => $plan['features'],
        ];
    }

    private function addon(float $list): array
    {
        $promo = $this->promo();

        return [
            'list' => $list,
            'shown' => round($promo['active'] ? $list * (1 - $promo['percent'] / 100) : $list, 2),
        ];
    }

    private function discount(int $list, int $percent): int
    {
        return (int) round($list * (1 - $percent / 100));
    }
}
