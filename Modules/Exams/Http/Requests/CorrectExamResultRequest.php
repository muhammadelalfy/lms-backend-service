<?php

declare(strict_types=1);

namespace Modules\Exams\Http\Requests;

use Illuminate\Validation\Validator;
use Modules\Exams\DTOs\ExamResultData;

/** The admin's audited correction: a reason (10+ characters) and at least one field to change. */
class CorrectExamResultRequest extends ExamResultRequest
{
    protected string $ability = 'correct';

    protected function deniedMessage(): string
    {
        return 'تصحيح النتائج المنشورة متاح للإدارة فقط.';
    }

    public function rules(): array
    {
        return [
            'reason' => 'required|string|min:10|max:1000',
            'student_id' => 'sometimes|integer|exists:students,id',
            'title' => 'sometimes|string|max:180',
            'score' => 'sometimes|integer|min:0',
            'max_score' => 'sometimes|integer|min:1',
            'taken_at' => 'sometimes|date',
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'سبب التصحيح مطلوب.',
            'reason.min' => 'اكتب سبباً واضحاً للتصحيح (10 أحرف على الأقل).',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_intersect(array_keys($this->all()), ExamResultData::FIELDS) === []) {
                $validator->errors()->add('changes', 'أرسل حقلاً واحداً على الأقل لتصحيحه: الدرجة أو الدرجة العظمى أو العنوان أو التاريخ أو الطالب.');
            }
        }];
    }
}
