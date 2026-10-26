<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Ai\Enums\QuestionType;
use Modules\Ai\Models\ExamBlueprint;
use Modules\Ai\Models\Material;
use Modules\Ai\Models\MaterialSection;

/**
 * The fields of a blueprint, their rules and the checks across fields (percentages sum to 100,
 * marks add up to the total, sources are the teacher's own materials). Shared by create, update and
 * an inline estimate. On update only the sent fields change; the cross-field checks use the stored
 * value of a field that was not sent.
 */
trait DescribesBlueprint
{
    public const DIFFICULTIES = ['easy', 'medium', 'hard'];

    public const BLOOM = ['remember', 'understand', 'apply', 'analyze', 'evaluate', 'create'];

    /** @return array<string, mixed> */
    protected function blueprintRules(bool $partial): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'title' => [$required, 'string', 'max:300'],
            'is_template' => ['sometimes', 'boolean'],
            'is_private' => ['sometimes', 'boolean'],
            'grade_level' => ['nullable', 'string', 'max:200'],
            'subject' => ['nullable', 'string', 'max:200'],
            'language' => ['sometimes', Rule::in(['ar', 'en'])],
            'outcomes' => ['sometimes', 'nullable', 'array', 'max:50'],
            'outcomes.*.id' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/', 'distinct'],
            'outcomes.*.text' => ['required', 'string', 'max:500'],
            'outcomes_text' => ['sometimes', 'nullable', 'string', 'max:25000', 'prohibits:outcomes'],
            'difficulty_mix' => ['sometimes', 'nullable', 'array:'.implode(',', self::DIFFICULTIES)],
            'difficulty_mix.*' => ['numeric', 'min:0', 'max:100'],
            'bloom_mix' => ['sometimes', 'nullable', 'array:'.implode(',', self::BLOOM)],
            'bloom_mix.*' => ['numeric', 'min:0', 'max:100'],
            'types' => [$required, 'array', 'min:1', 'max:5'],
            'types.*.type' => ['required', Rule::in(QuestionType::values()), 'distinct'],
            'types.*.count' => ['required', 'integer', 'min:1', 'max:100'],
            'types.*.marks' => ['required', 'integer', 'min:1', 'max:100'],
            'total_marks' => [$required, 'integer', 'min:1', 'max:10000'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:600'],
            'sources' => ['sometimes', 'nullable', 'array', 'max:'.(int) config('ai.generation.max_materials')],
            'sources.*.material_id' => ['required', 'integer', 'distinct'],
            'sources.*.section_ids' => ['nullable', 'array', 'max:500'],
            'sources.*.section_ids.*' => ['integer'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'avoid_topics' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    protected function blueprintMessages(): array
    {
        return [
            'title.required' => 'اكتب عنوان الامتحان.',
            'title.max' => 'العنوان طويل جداً.',
            'types.required' => 'حدد نوعاً واحداً على الأقل من الأسئلة.',
            'types.min' => 'حدد نوعاً واحداً على الأقل من الأسئلة.',
            'types.max' => 'أنواع الأسئلة أكثر من المسموح.',
            'types.*.type.in' => 'نوع سؤال غير مدعوم.',
            'types.*.type.distinct' => 'كل نوع أسئلة يُذكر مرة واحدة.',
            'types.*.count.*' => 'عدد الأسئلة لكل نوع بين 1 و100.',
            'types.*.marks.*' => 'درجة السؤال عدد صحيح بين 1 و100.',
            'total_marks.required' => 'اكتب الدرجة الكلية للامتحان.',
            'total_marks.*' => 'الدرجة الكلية عدد صحيح موجب.',
            'difficulty_mix.array' => 'مستويات الصعوبة: سهل، متوسط، صعب فقط.',
            'difficulty_mix.*.*' => 'نسبة الصعوبة بين 0 و100.',
            'bloom_mix.array' => 'مستوى بلوم غير معروف.',
            'bloom_mix.*.*' => 'نسبة مستوى بلوم بين 0 و100.',
            'outcomes.max' => 'نواتج التعلم أكثر من 50.',
            'outcomes.*.text.required' => 'ناتج تعلم فارغ.',
            'outcomes.*.text.max' => 'ناتج التعلم طويل جداً.',
            'outcomes.*.id.*' => 'معرّف ناتج التعلم غير صالح أو مكرر.',
            'outcomes_text.prohibits' => 'أرسل نواتج التعلم قائمةً أو نصاً، لا الاثنين.',
            'duration_minutes.*' => 'مدة الامتحان بين 1 و600 دقيقة.',
            'language.in' => 'لغة الامتحان العربية أو الإنجليزية.',
            'sources.max' => 'الحد الأقصى '.config('ai.generation.max_materials').' مواد للامتحان الواحد.',
            'sources.*.material_id.*' => 'مادة غير صالحة أو مكررة.',
            'sources.*.section_ids.*' => 'أجزاء المادة غير صالحة.',
            'instructions.max' => 'التعليمات طويلة جداً.',
            'avoid_topics.max' => 'الموضوعات المستبعدة طويلة جداً.',
        ];
    }

    /** The checks across fields, run after the single-field rules passed. */
    protected function checkBlueprint(Validator $validator, ?ExamBlueprint $stored = null): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }
        $data = $validator->validated();
        $this->checkMix($validator, $data, 'difficulty_mix', 'مجموع نسب الصعوبة يجب أن يساوي 100٪.');
        $this->checkMix($validator, $data, 'bloom_mix', 'مجموع نسب مستويات بلوم يجب أن يساوي 100٪.');

        $types = array_key_exists('types', $data) ? $data['types'] : $stored?->types();
        $total = array_key_exists('total_marks', $data) ? $data['total_marks'] : $stored?->total_marks;
        if (is_array($types) && $total !== null) {
            $sum = array_sum(array_map(fn ($t) => (int) $t['count'] * (int) $t['marks'], $types));
            if ($sum !== (int) $total) {
                $validator->errors()->add('total_marks', "مجموع درجات الأسئلة ({$sum}) لا يساوي الدرجة الكلية ({$total}).");
            }
            $questions = array_sum(array_map(fn ($t) => (int) $t['count'], $types));
            if ($questions > (int) config('ai.generation.max_questions')) {
                $validator->errors()->add('types', 'الحد الأقصى '.config('ai.generation.max_questions').' سؤالاً في الامتحان الواحد.');
            }
        }

        if (! empty($data['sources'])) {
            $this->checkSources($validator, $data['sources']);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function checkMix(Validator $validator, array $data, string $field, string $message): void
    {
        if (! is_array($data[$field] ?? null) || $data[$field] === []) {
            return;
        }
        if (abs(array_sum(array_map('floatval', $data[$field])) - 100) > 0.5) {
            $validator->errors()->add($field, $message);
        }
    }

    /** Every source is a material of this teacher (any material for the admin) and its sections are its own. */
    private function checkSources(Validator $validator, array $sources): void
    {
        $user = $this->user();
        $ids = array_map(fn ($s) => (int) $s['material_id'], $sources);
        $materials = Material::query()->whereIn('id', $ids)
            ->when(! $user->isAnyRole('admin'), fn ($q) => $q->where('owner_user_id', $user->id))
            ->pluck('id')->all();
        $sectionIds = array_merge(...array_map(fn ($s) => array_map('intval', (array) ($s['section_ids'] ?? [])), $sources));
        $owners = $sectionIds === [] ? [] : MaterialSection::query()->whereIn('id', $sectionIds)->pluck('material_id', 'id')->all();

        foreach ($sources as $i => $source) {
            $id = (int) $source['material_id'];
            if (! in_array($id, $materials, true)) {
                $validator->errors()->add("sources.{$i}.material_id", 'المادة غير موجودة.');

                continue;
            }
            foreach ((array) ($source['section_ids'] ?? []) as $sectionId) {
                if ((int) ($owners[(int) $sectionId] ?? 0) !== $id) {
                    $validator->errors()->add("sources.{$i}.section_ids", 'جزء لا ينتمي إلى هذه المادة.');
                    break;
                }
            }
        }
    }
}
