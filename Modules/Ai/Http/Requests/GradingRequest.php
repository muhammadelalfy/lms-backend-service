<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests;

use Modules\Ai\Models\GradingSuggestion;
use Modules\Exams\Models\ExamTemplate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Base of the grading-queue requests: admin (all of the center) or teacher (answers to their own
 * exams); another teacher's item is "not found". Not plan-gated: after a downgrade (or with AI off)
 * the teacher must still be able to finish grading by hand.
 */
abstract class GradingRequest extends AiRequest
{
    protected function needsPlan(): bool
    {
        return false;
    }

    protected function deniedMessage(): string
    {
        return 'لا تملك صلاحية التصحيح.';
    }

    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user->isAnyRole('admin', 'teacher')) {
            return false;
        }
        $suggestion = $this->route('suggestion');
        if ($suggestion instanceof GradingSuggestion && ! $user->isAnyRole('admin')
            && (int) ExamTemplate::query()->whereKey($suggestion->template_id)->value('created_by') !== $user->id) {
            throw new NotFoundHttpException('الإجابة غير موجودة.');
        }

        return $this->allowed(true);
    }

    public function suggestion(): GradingSuggestion
    {
        $suggestion = $this->route('suggestion');
        if (! $suggestion instanceof GradingSuggestion) {
            throw new \LogicException('The route names no grading item.');
        }

        return $suggestion;
    }
}
