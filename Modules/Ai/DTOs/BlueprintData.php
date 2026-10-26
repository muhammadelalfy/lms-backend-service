<?php

declare(strict_types=1);

namespace Modules\Ai\DTOs;

/**
 * The columns a blueprint request sets (only the fields it sent). Outcomes get ids O1..On where the
 * teacher gave none; a pasted block becomes one outcome per line.
 */
final readonly class BlueprintData
{
    /** @param  array<string, mixed>  $attributes */
    public function __construct(public array $attributes) {}

    /** @param  array<string, mixed>  $validated */
    public static function fromValidated(array $validated): self
    {
        $attributes = array_intersect_key($validated, array_flip([
            'title', 'is_template', 'is_private', 'grade_level', 'subject', 'language', 'total_marks',
            'difficulty_mix', 'bloom_mix', 'instructions', 'avoid_topics',
        ]));

        if (array_key_exists('duration_minutes', $validated)) {
            $attributes['duration_minutes'] = $validated['duration_minutes'] ?? 60;
        }
        foreach (['difficulty_mix', 'bloom_mix'] as $mix) {
            if (array_key_exists($mix, $attributes)) {
                $attributes[$mix] = $attributes[$mix] ? array_map(fn ($v) => round((float) $v, 2), $attributes[$mix]) : null;
            }
        }
        if (array_key_exists('outcomes_text', $validated)) {
            $attributes['outcomes'] = self::outcomes(self::lines((string) $validated['outcomes_text']));
        } elseif (array_key_exists('outcomes', $validated)) {
            $attributes['outcomes'] = self::outcomes((array) $validated['outcomes']);
        }
        if (array_key_exists('types', $validated)) {
            $attributes['type_counts'] = [];
            $attributes['marks_per_type'] = [];
            foreach ($validated['types'] as $type) {
                $attributes['type_counts'][$type['type']] = (int) $type['count'];
                $attributes['marks_per_type'][$type['type']] = (int) $type['marks'];
            }
        }
        if (array_key_exists('sources', $validated)) {
            $attributes['source_selection'] = array_map(fn ($s) => [
                'material_id' => (int) $s['material_id'],
                'section_ids' => isset($s['section_ids']) && $s['section_ids'] !== [] ? array_values(array_unique(array_map('intval', $s['section_ids']))) : null,
            ], array_values((array) $validated['sources']));
        }

        return new self($attributes);
    }

    /** Defaults of a new blueprint for the fields a create request may leave out. */
    public function forCreate(): array
    {
        return $this->attributes + [
            'is_template' => false, 'is_private' => false, 'language' => 'ar', 'outcomes' => [],
            'duration_minutes' => 60, 'source_selection' => [],
        ];
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            // "1. ...", "- ...", "• ..." are list markers, not part of the outcome.
            $line = trim((string) preg_replace('/^\s*(?:[-*•▪]|[0-9٠-٩]+[.)\-]|[(][0-9٠-٩]+[)])\s*/u', '', $line));
            if ($line !== '') {
                $lines[] = mb_substr($line, 0, 500);
            }
        }

        return array_slice($lines, 0, 50);
    }

    /**
     * @param  list<string|array{id?: string|null, text: string}>  $items
     * @return list<array{id: string, text: string}>
     */
    private static function outcomes(array $items): array
    {
        $given = array_filter(array_map(fn ($i) => is_array($i) ? ($i['id'] ?? null) : null, $items));
        $out = [];
        $n = 0;
        foreach ($items as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : null;
            if ($id === null || $id === '') {
                do {
                    $id = 'O'.++$n;
                } while (in_array($id, $given, true));
            }
            $out[] = ['id' => (string) $id, 'text' => trim(is_array($item) ? (string) $item['text'] : $item)];
        }

        return $out;
    }
}
