<?php

declare(strict_types=1);

namespace Modules\Tenancy\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/** One more of something the center's plan caps: answered 402 `plan_limit`, the shape every cap uses. */
class PlanLimitReached extends RuntimeException implements ShouldntReport
{
    private const NOUNS = [
        'students' => 'الطلاب',
        'teachers' => 'المدرسين',
        'subjects' => 'المواد',
        'classes_per_subject' => 'الفصول لكل مادة',
        'assistants' => 'المساعدين',
        'storage_gb' => 'مساحة التخزين',
    ];

    public function __construct(public readonly string $cap, public readonly int $limit, public readonly int $used)
    {
        $noun = self::NOUNS[$cap] ?? $cap;
        parent::__construct("وصل مركزك إلى الحد الأقصى من {$noun} في باقته ({$limit}). رقِّ الباقة لإضافة المزيد.");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'plan_limit',
            'cap' => $this->cap,
            'limit' => $this->limit,
            'used' => $this->used,
        ], 402);
    }
}
