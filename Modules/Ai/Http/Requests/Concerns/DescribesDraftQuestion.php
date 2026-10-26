<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Ai\Enums\QuestionType;
use Modules\Ai\Models\DraftQuestion;
use Modules\Ai\Support\Taxonomy;

/**
 * The fields a teacher may set on a draft question, and the checks across them on the question as it
 * will be (stored values overlaid with the sent ones): an MCQ's answer is one of its options, a
 * rubric's criteria add up to the question's marks.
 */
trait DescribesDraftQuestion
{
    /** @return array<string, mixed> */
    protected function questionRules(bool $partial): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'type' => [$required, Rule::in(QuestionType::values())],
            'prompt' => [$required, 'string', 'max:4000'],
            'options' => ['sometimes', 'nullable', 'array', 'min:2', 'max:6'],
            'options.*' => ['required', 'string', 'max:1000', 'distinct'],
            'correct_answer' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'explanation' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'model_answer' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'rubric' => ['sometimes', 'nullable', 'array:criteria,accept_alternatives'],
            'rubric.criteria' => ['required_with:rubric', 'array', 'min:1', 'max:10'],
            'rubric.criteria.*.id' => ['required', 'string', 'max:20', 'distinct'],
            'rubric.criteria.*.text' => ['required', 'string', 'max:1000'],
            'rubric.criteria.*.marks' => ['required', 'numeric', 'min:0', 'max:100'],
            'rubric.accept_alternatives' => ['sometimes', 'array', 'max:20'],
            'rubric.accept_alternatives.*' => ['string', 'max:1000'],
            'marks' => [$required, 'integer', 'min:1', 'max:100'],
            'difficulty' => ['sometimes', 'nullable', Rule::in(Taxonomy::DIFFICULTIES)],
            'bloom' => ['sometimes', 'nullable', Rule::in(Taxonomy::BLOOM)],
            'outcome_ids' => ['sometimes', 'nullable', 'array', 'max:50'],
            'outcome_ids.*' => ['string', 'max:20'],
        ];
    }

    /** @return array<string, string> */
    protected function questionMessages(): array
    {
        return [
            'type.*' => 'نوع سؤال غير مدعوم.',
            'prompt.required' => 'اكتب نص السؤال.',
            'prompt.max' => 'نص السؤال طويل جداً.',
            'options.min' => 'السؤال يحتاج خيارين على الأقل.',
            'options.max' => 'الحد الأقصى 6 خيارات.',
            'options.*.distinct' => 'الخيارات يجب ألا تتكرر.',
            'options.*.*' => 'خيار غير صالح.',
            'marks.*' => 'درجة السؤال عدد صحيح بين 1 و100.',
            'rubric.array' => 'معيار التصحيح غير صالح.',
            'rubric.criteria.*' => 'معيار التصحيح يحتاج بنداً واحداً على الأقل.',
            'rubric.criteria.*.*.*' => 'بند غير صالح في معيار التصحيح.',
            'difficulty.in' => 'مستوى الصعوبة غير معروف.',
            'bloom.in' => 'مستوى بلوم غير معروف.',
        ];
    }

    protected function checkQuestion(Validator $validator, ?DraftQuestion $stored = null): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }
        $data = $validator->validated();
        $pick = fn (string $key) => array_key_exists($key, $data) ? $data[$key] : $stored?->{$key};
        $type = QuestionType::from($data['type'] ?? $stored->type->value);
        $marks = (int) $pick('marks');

        if (! $type->isWritten()) {
            $options = array_values((array) ($pick('options') ?? ($type === QuestionType::TrueFalse ? ['صواب', 'خطأ'] : [])));
            if ($type === QuestionType::Mcq && count($options) < 2) {
                $validator->errors()->add('options', 'سؤال الاختيار من متعدد يحتاج خيارين على الأقل.');
            }
            if ($type === QuestionType::TrueFalse && count($options) !== 2) {
                $validator->errors()->add('options', 'سؤال الصواب والخطأ له خياران فقط.');
            }
            if (! in_array($pick('correct_answer'), $options, true)) {
                $validator->errors()->add('correct_answer', 'الإجابة الصحيحة يجب أن تكون أحد الخيارات.');
            }

            return;
        }

        $rubric = $pick('rubric');
        if (is_array($rubric) && ! empty($rubric['criteria'])) {
            $sum = array_sum(array_map(fn ($c) => (float) $c['marks'], $rubric['criteria']));
            if (abs($sum - $marks) > 0.01) {
                $validator->errors()->add('rubric', "مجموع درجات بنود التصحيح ({$sum}) لا يساوي درجة السؤال ({$marks}).");
            }
        }
    }
}
