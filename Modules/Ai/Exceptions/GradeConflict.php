<?php

declare(strict_types=1);

namespace Modules\Ai\Exceptions;

use Illuminate\Http\JsonResponse;

/** A grading request that contradicts what is already decided (409). */
final class GradeConflict extends \RuntimeException
{
    /** @param array<string, mixed> $extra */
    public function __construct(public readonly string $reason, string $message, public readonly array $extra = [])
    {
        parent::__construct($message);
    }

    /** Changing a decided grade goes through the exam result's audited correction (S6). */
    public static function alreadyDecided(?int $resultId): self
    {
        return new self('already_decided', 'تم اعتماد درجة هذه الإجابة بالفعل. لتغييرها عدّل نتيجة الاختبار (أو اطلب تصحيحاً موثقاً من الإدارة إن كانت منشورة).', ['exam_result_id' => $resultId]);
    }

    public static function countMismatch(int $count): self
    {
        return new self('count_mismatch', "تغيّر عدد الإجابات المطابقة إلى {$count}. راجع العدد ثم أكّد مرة أخرى.", ['count' => $count]);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->reason, ...$this->extra], 409);
    }

    public function report(): bool
    {
        return true;
    }
}
