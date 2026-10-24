<?php

declare(strict_types=1);

namespace Modules\Courses\Support;

use Illuminate\Validation\ValidationException;
use Modules\Courses\Enums\Pricing;
use Modules\Courses\Models\Course;

/** The pricing rules in one place: a paid course needs a price, a discount is below it, a free one carries none. */
final class CoursePricing
{
    /**
     * @param  array<string, mixed>  $data  the attributes being saved (possibly partial)
     * @return array<string, mixed>
     */
    public static function settle(array $data, ?Course $course = null): array
    {
        $pricing = $data['pricing'] ?? $course?->pricing->value ?? Pricing::Free->value;
        if ($pricing === Pricing::Free->value) {
            return $data + ['price_amount' => null, 'discounted_price_amount' => null];
        }

        $price = array_key_exists('price_amount', $data) ? $data['price_amount'] : $course?->price_amount;
        if ($price === null) {
            throw ValidationException::withMessages(['price_amount' => 'الدورة المدفوعة تحتاج سعراً.']);
        }
        $discount = array_key_exists('discounted_price_amount', $data) ? $data['discounted_price_amount'] : $course?->discounted_price_amount;
        if ($discount !== null && $discount >= $price) {
            throw ValidationException::withMessages(['discounted_price_amount' => 'السعر بعد الخصم يجب أن يكون أقل من السعر.']);
        }

        return $data;
    }
}
