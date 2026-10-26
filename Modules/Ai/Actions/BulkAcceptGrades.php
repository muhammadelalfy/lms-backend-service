<?php

declare(strict_types=1);

namespace Modules\Ai\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Ai\Enums\GradeDecision;
use Modules\Ai\Enums\SuggestionStatus;
use Modules\Ai\Events\GradeDecided;
use Modules\Ai\Exceptions\GradeConflict;
use Modules\Ai\Models\AnswerGrade;
use Modules\Ai\Models\GradingSuggestion;
use Modules\Ai\Services\SessionResults;
use Modules\Auth\Models\User;

/**
 * Accepts many suggestions at once, always explicitly: either the listed ids, or every undecided
 * suggestion of one exam at or above a confidence, with the count the teacher confirmed echoed back
 * (409 with the real count if it changed). Never takes an item marked for attention (low confidence,
 * suspected injection) or one without a suggestion. One insert, one finalize, one transaction.
 */
final class BulkAcceptGrades
{
    public function __construct(private readonly SessionResults $results) {}

    /**
     * @param  list<int>|null  $ids
     * @return array{accepted: int, skipped_ids: list<int>}
     */
    public function handle(User $actor, ?array $ids, ?float $minConfidence, ?int $examId, ?int $count): array
    {
        return DB::transaction(function () use ($actor, $ids, $minConfidence, $examId, $count) {
            $query = GradingSuggestion::query()->visibleTo($actor)
                ->whereIn('status', [SuggestionStatus::Ready->value, SuggestionStatus::Empty->value])
                ->whereNotNull('suggested_score')
                ->where('needs_attention', false)
                ->whereDoesntHave('grade')
                ->lockForUpdate();
            if ($ids !== null) {
                $query->whereIn('id', $ids);
            } else {
                $query->where('template_id', $examId)->where('confidence', '>=', $minConfidence);
            }
            $items = $query->orderBy('id')->limit((int) config('ai.grading.bulk_max') + 1)->get();
            if ($ids === null && $items->count() !== $count) {
                throw GradeConflict::countMismatch($items->count());
            }
            $items = $items->take((int) config('ai.grading.bulk_max'));

            $now = now();
            if ($items->isNotEmpty()) {
                AnswerGrade::query()->insert($items->map(fn (GradingSuggestion $s) => [
                    'suggestion_id' => $s->id,
                    'session_id' => $s->session_id,
                    'question_id' => $s->question_id,
                    'session_answer_id' => $s->session_answer_id,
                    'final_score' => $s->suggested_score,
                    'final_feedback' => $s->feedback,
                    'decision' => GradeDecision::Accepted->value,
                    'decided_by' => $actor->id,
                    'decided_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            }
            $reviewed = $this->results->finalize($items->pluck('session_id')->unique()->values()->all(), $actor->id);
            $gradeIds = AnswerGrade::query()->whereIn('suggestion_id', $items->modelKeys())->pluck('id')->all();
            if ($gradeIds !== []) {
                DB::afterCommit(fn () => event(new GradeDecided($gradeIds, $actor->id, $reviewed)));
            }
            $accepted = $items->modelKeys();

            return [
                'accepted' => count($accepted),
                'skipped_ids' => $ids === null ? [] : array_values(array_diff($ids, $accepted)),
            ];
        });
    }
}
