<?php

declare(strict_types=1);

namespace Modules\Ai\DTOs;

/** The fields of a draft question a teacher's request sets (only those it sent). */
final readonly class DraftQuestionData
{
    /** @param  array<string, mixed>  $attributes */
    public function __construct(public array $attributes) {}

    /** @param  array<string, mixed>  $validated */
    public static function fromValidated(array $validated): self
    {
        $attributes = array_intersect_key($validated, array_flip([
            'type', 'prompt', 'options', 'correct_answer', 'explanation', 'model_answer', 'rubric', 'marks',
            'difficulty', 'bloom', 'outcome_ids',
        ]));
        if (isset($attributes['prompt'])) {
            $attributes['prompt'] = trim($attributes['prompt']);
        }
        if (array_key_exists('rubric', $attributes) && is_array($attributes['rubric'])) {
            $attributes['rubric'] = [
                'criteria' => array_map(fn ($c) => ['id' => (string) $c['id'], 'text' => (string) $c['text'], 'marks' => round((float) $c['marks'], 2)], $attributes['rubric']['criteria']),
                'accept_alternatives' => array_values($attributes['rubric']['accept_alternatives'] ?? []),
            ];
        }
        if (isset($attributes['options'])) {
            $attributes['options'] = array_values($attributes['options']);
        }

        return new self($attributes);
    }
}
