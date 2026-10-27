<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests;

use Illuminate\Validation\Validator;
use Modules\Exams\Models\ExamQuestion;
use Modules\Exams\Models\ExamTemplate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reading or setting an exam question's model answer and rubric: admin, or the teacher who owns the
 * exam (another teacher's question is "not found"). Setting one needs the plan's AI exams feature;
 * reading it does not.
 */
class QuestionRubricRequest extends AiRequest
{
    protected function needsPlan(): bool
    {
        return $this->isMethod('PUT');
    }

    protected function deniedMessage(): string
    {
        return 'لا تملك صلاحية هذا الامتحان.';
    }

    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user->isAnyRole('admin', 'teacher')) {
            return false;
        }
        if (! $user->isAnyRole('admin') && (int) ExamTemplate::query()->whereKey($this->question()->template_id)->value('created_by') !== $user->id) {
            throw new NotFoundHttpException('السؤال غير موجود.');
        }

        return $this->allowed(true);
    }

    public function rules(): array
    {
        if (! $this->isMethod('PUT')) {
            return [];
        }

        return [
            'model_answer' => ['nullable', 'string', 'max:8000'],
            'rubric' => ['nullable', 'array'],
            'rubric.criteria' => ['required_with:rubric', 'array', 'min:1', 'max:10'],
            'rubric.criteria.*.id' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/', 'distinct'],
            'rubric.criteria.*.text' => ['required', 'string', 'max:1000'],
            'rubric.criteria.*.marks' => ['required', 'numeric', 'min:0', 'max:1000'],
            'rubric.accept_alternatives' => ['nullable', 'array', 'max:20'],
            'rubric.accept_alternatives.*' => ['string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        if (! $this->isMethod('PUT')) {
            return [];
        }

        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $question = $this->question();
            if ($question->type !== 'essay') {
                $validator->errors()->add('question', 'الإجابة النموذجية ومعيار التصحيح متاحان للأسئلة المقالية فقط.');

                return;
            }
            $criteria = $this->input('rubric.criteria');
            if (blank($this->input('model_answer')) && empty($criteria)) {
                $validator->errors()->add('model_answer', 'أدخل إجابة نموذجية أو معيار تصحيح على الأقل.');

                return;
            }
            if (! empty($criteria)) {
                $sum = array_sum(array_map(fn ($c) => (float) $c['marks'], $criteria));
                if (abs($sum - (float) $question->points) > 0.001) {
                    $validator->errors()->add('rubric.criteria', "مجموع درجات المعايير ({$sum}) يجب أن يساوي درجة السؤال ({$question->points}).");
                }
            }
        }];
    }

    public function question(): ExamQuestion
    {
        $question = $this->route('question');
        if (! $question instanceof ExamQuestion) {
            throw new \LogicException('The route names no exam question.');
        }

        return $question;
    }
}
