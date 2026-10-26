<?php

declare(strict_types=1);

namespace Modules\Exams\Exceptions;

use Illuminate\Http\JsonResponse;

/** The result is not in the state the action needs (409): editing a published one, publishing twice... */
final class ExamResultLocked extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function published(): self
    {
        return new self('result_published', 'هذه النتيجة منشورة ولا يمكن تعديلها أو حذفها مباشرة؛ يلزم تصحيح موثق من الإدارة.');
    }

    public static function alreadyPublished(): self
    {
        return new self('already_published', 'هذه النتيجة منشورة بالفعل.');
    }

    public static function stillDraft(): self
    {
        return new self('result_draft', 'هذه النتيجة ما زالت مسودة؛ عدّلها مباشرة ثم انشرها.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->reason], 409);
    }

    public function report(): bool
    {
        return true;
    }
}
