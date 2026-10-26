<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Modules\Ai\Enums\QuestionState;
use Modules\Ai\Enums\QuestionType;
use Modules\Ai\Models\DraftQuestion;
use Modules\Ai\Models\GenerationJob;
use Modules\Ai\Support\Taxonomy;

/**
 * Saves the questions the AI service returned into a draft, with their provenance, in one insert.
 * The service's answer is untrusted: unknown types are dropped, strings are bounded, and a citation
 * naming a material outside the job's sources keeps no material id and flags the question.
 */
final class DraftQuestionWriter
{
    /**
     * @param  list<array<string, mixed>>  $questions  as returned by /v1/exams/generate
     * @param  list<string>  $materialIds  the job's material ids (as sent)
     * @return list<int> ids of the saved questions, in order
     */
    public function insert(GenerationJob $job, array $questions, array $materialIds, int $firstOrdinal, string $promptVersion, string $model, string $origin = 'ai', ?int $replacesId = null): array
    {
        $now = now();
        $rows = [];
        $ordinal = $firstOrdinal;
        foreach ($questions as $q) {
            $type = QuestionType::tryFrom((string) ($q['type'] ?? ''));
            $prompt = trim((string) ($q['prompt'] ?? ''));
            if ($type === null || $prompt === '') {
                continue;
            }
            $citation = is_array($q['citation'] ?? null) ? $q['citation'] : [];
            $cited = (string) ($citation['material_id'] ?? '');
            $known = in_array($cited, $materialIds, true);
            $written = $type->isWritten();
            $options = $written ? null : array_values(array_map(fn ($o) => mb_substr(trim((string) $o), 0, 1000), array_filter((array) ($q['options'] ?? []), 'is_scalar')));

            $rows[] = [
                'draft_id' => $job->draft_id,
                'job_id' => $job->id,
                'ordinal' => $ordinal++,
                'type' => $type->value,
                'prompt' => mb_substr($prompt, 0, 4000),
                'options' => $options === null ? null : json_encode($options, JSON_UNESCAPED_UNICODE),
                'correct_answer' => $this->text($written ? ($q['model_answer'] ?? $q['correct_answer'] ?? null) : ($q['correct_answer'] ?? null), 4000),
                'explanation' => $this->text($q['explanation'] ?? null, 4000),
                'model_answer' => $written ? $this->text($q['model_answer'] ?? $q['correct_answer'] ?? null, 4000) : null,
                'rubric' => $written && is_array($q['rubric'] ?? null) ? json_encode($this->rubric($q['rubric']), JSON_UNESCAPED_UNICODE) : null,
                'marks' => max(1, min(100, (int) round((float) ($q['marks'] ?? 1)))),
                'difficulty' => in_array($q['difficulty'] ?? null, Taxonomy::DIFFICULTIES, true) ? $q['difficulty'] : null,
                'bloom' => in_array($q['bloom'] ?? null, Taxonomy::BLOOM, true) ? $q['bloom'] : null,
                'outcome_ids' => json_encode(array_values(array_map('strval', array_filter((array) ($q['outcome_ids'] ?? []), 'is_scalar')))),
                'source_material_id' => $known ? (int) $cited : null,
                'source_chunk_ref' => $this->text($citation['chunk_ref'] ?? null, 60),
                'source_label' => $this->text($citation['label'] ?? null, 250),
                'source_quote' => $this->text($citation['quote'] ?? null, 1000),
                'source_ocr' => (bool) ($citation['ocr'] ?? false),
                'ai_ref' => $this->text($q['ref'] ?? null, 40),
                // A citation outside the job's materials cannot be trusted: review it first.
                'flagged' => ! $known,
                'verify_issues' => $known ? null : json_encode(['المصدر المذكور ليس من مواد هذا الامتحان.'], JSON_UNESCAPED_UNICODE),
                'state' => QuestionState::Pending->value,
                'origin' => $origin,
                'replaces_id' => $replacesId,
                'prompt_version' => mb_substr($promptVersion, 0, 40),
                'model_id' => mb_substr($model, 0, 120),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if ($rows === []) {
            return [];
        }
        $before = (int) DraftQuestion::query()->where('job_id', $job->id)->max('id');
        DraftQuestion::query()->insert($rows);

        return DraftQuestion::query()->where('job_id', $job->id)->where('id', '>', $before)
            ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return array{criteria: list<array{id: string, text: string, marks: float}>, accept_alternatives: list<string>} */
    public function rubric(array $rubric): array
    {
        $criteria = [];
        foreach (array_slice(array_filter((array) ($rubric['criteria'] ?? []), 'is_array'), 0, 10) as $i => $c) {
            $criteria[] = [
                'id' => mb_substr((string) ($c['id'] ?? 'c'.($i + 1)), 0, 20),
                'text' => mb_substr((string) ($c['text'] ?? ''), 0, 1000),
                'marks' => round((float) ($c['marks'] ?? 0), 2),
            ];
        }

        return [
            'criteria' => $criteria,
            'accept_alternatives' => array_values(array_map(fn ($a) => mb_substr((string) $a, 0, 1000), array_slice(array_filter((array) ($rubric['accept_alternatives'] ?? []), 'is_scalar'), 0, 20))),
        ];
    }

    private function text(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
