<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Modules\Ai\Enums\MaterialStatus;
use Modules\Ai\Exceptions\GenerationRefused;
use Modules\Ai\Models\Material;
use Modules\Ai\Models\MaterialSection;
use Modules\Ai\Support\JobError;
use Modules\Auth\Models\User;

/**
 * Turns a blueprint snapshot into what the AI service reads: the blueprint, the material ids and the
 * section ids (honouring the teacher's picks and exclusions), and the batches of at most 10 questions
 * a long exam is generated in. Only ids leave this app, never material text.
 */
final class GenerationPayload
{
    /**
     * The sources of a blueprint as the AI service names them, checked: ready materials of the actor
     * (any of the center for the admin). Stored in the job's snapshot so a job never re-reads them.
     *
     * @param  list<array{material_id: int, section_ids: list<int>|null}>  $sources
     * @return array{material_ids: list<string>, section_ids: list<int>|null}
     *
     * @throws GenerationRefused when nothing usable is left
     */
    public function resolveSources(array $sources, User $actor): array
    {
        $ids = array_values(array_unique(array_map(fn ($s) => (int) $s['material_id'], $sources)));
        $ready = $ids === [] ? [] : Material::query()->whereIn('id', $ids)
            ->where('status', MaterialStatus::Ready->value)
            ->when(! $actor->isAnyRole('admin'), fn ($q) => $q->where('owner_user_id', $actor->id))
            ->pluck('id')->all();
        if ($ready === [] || count($ready) !== count($ids)) {
            throw new GenerationRefused(JobError::NO_MATERIAL, $ready === []
                ? 'اختر مادة جاهزة واحدة على الأقل قبل التوليد.'
                : 'بعض المواد المختارة غير جاهزة أو لم تعد موجودة. راجع المصادر.');
        }

        $sections = MaterialSection::query()->whereIn('material_id', $ready)->get(['id', 'material_id', 'excluded']);
        $picked = array_filter($sources, fn ($s) => ! empty($s['section_ids']));
        if ($picked === [] && ! $sections->contains('excluded', true)) {
            return ['material_ids' => array_map('strval', $ready), 'section_ids' => null];
        }

        $chosen = [];
        foreach ($sources as $source) {
            $own = $sections->where('material_id', (int) $source['material_id'])->where('excluded', false);
            $wanted = empty($source['section_ids']) ? null : array_map('intval', $source['section_ids']);
            foreach ($own as $section) {
                if ($wanted === null || in_array($section->id, $wanted, true)) {
                    $chosen[] = $section->id;
                }
            }
        }
        if ($chosen === []) {
            throw new GenerationRefused(JobError::NO_MATERIAL, 'كل أجزاء المواد المختارة مستبعدة. اختر أجزاءً للتوليد منها.');
        }

        return ['material_ids' => array_map('strval', $ready), 'section_ids' => array_values(array_unique($chosen))];
    }

    /**
     * The blueprint in the AI service's shape, for the given types (a batch) and outcomes.
     *
     * @param  list<array{type: string, count: int, marks: int}>|null  $types  null = all of the snapshot's
     * @param  list<string>|null  $outcomeIds  null = all
     */
    public function blueprint(array $snapshot, ?array $types = null, ?array $outcomeIds = null): array
    {
        $types ??= $snapshot['types'];
        $outcomes = array_values(array_filter((array) $snapshot['outcomes'], fn ($o) => $outcomeIds === null || $outcomeIds === [] || in_array($o['id'], $outcomeIds, true)));

        return [
            'title' => (string) $snapshot['title'],
            'grade_level' => (string) ($snapshot['grade_level'] ?? ''),
            'subject' => (string) ($snapshot['subject'] ?? ''),
            'language' => (string) ($snapshot['language'] ?? 'ar'),
            'outcomes' => $outcomes === [] && $outcomeIds !== null ? array_values((array) $snapshot['outcomes']) : $outcomes,
            'difficulty_mix' => $snapshot['difficulty_mix'] ?: null,
            'bloom_mix' => $snapshot['bloom_mix'] ?: null,
            'types' => array_map(fn ($t) => ['type' => $t['type'], 'count' => (int) $t['count'], 'marks' => (int) $t['marks']], array_values($types)),
            // The whole exam's total only when the whole exam is asked; a batch has its own.
            'total_marks' => $types === $snapshot['types'] ? (int) $snapshot['total_marks'] : null,
            'avoid_topics' => (string) ($snapshot['avoid_topics'] ?? ''),
            'instructions' => (string) ($snapshot['instructions'] ?? ''),
        ];
    }

    /** The request body of estimate / generate for a snapshot whose sources were resolved. */
    public function request(array $snapshot, ?array $types = null, ?array $outcomeIds = null): array
    {
        return [
            'blueprint' => $this->blueprint($snapshot, $types, $outcomeIds),
            'material_ids' => $snapshot['ai_sources']['material_ids'],
            'section_ids' => $snapshot['ai_sources']['section_ids'],
        ];
    }

    /**
     * Splits the types into batches of at most `ai.generation.batch_size` questions, in order.
     *
     * @param  list<array{type: string, count: int, marks: int}>  $types
     * @return list<list<array{type: string, count: int, marks: int}>>
     */
    public function plan(array $types): array
    {
        $size = max(1, (int) config('ai.generation.batch_size'));
        $batches = [];
        $current = [];
        $room = $size;
        foreach ($types as $type) {
            $left = (int) $type['count'];
            while ($left > 0) {
                $take = min($left, $room);
                $current[] = ['type' => $type['type'], 'count' => $take, 'marks' => (int) $type['marks']];
                $left -= $take;
                $room -= $take;
                if ($room === 0) {
                    $batches[] = $current;
                    $current = [];
                    $room = $size;
                }
            }
        }
        if ($current !== []) {
            $batches[] = $current;
        }

        return $batches;
    }
}
