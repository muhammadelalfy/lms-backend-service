<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Ai\Enums\JobStatus;
use Modules\Ai\Enums\QuestionState;
use Modules\Ai\Enums\UsageKind;
use Modules\Ai\Exceptions\AiException;
use Modules\Ai\Models\DraftQuestion;
use Modules\Ai\Models\GenerationJob;
use Modules\Ai\Support\BulkUpdate;
use Modules\Exams\Models\QuestionBankQuestion;

/**
 * The verification pass over the questions a job wrote: the AI service checks each one against its
 * cited chunk (in batches of 20) and reports duplicates and overlap with the center's question bank.
 * A question is flagged ("يحتاج مراجعة") when the verdict is not `supported`, the confidence is
 * under 0.7, it duplicates another, or it could not be verified. Safe to run again: only questions
 * without a verdict are sent, and each batch is charged once.
 */
final class DraftVerifier
{
    private const VERDICTS = ['supported', 'partial', 'unsupported'];

    public function __construct(private readonly AiClient $ai, private readonly UnitBudget $budget) {}

    /**
     * @return bool false when it stopped because the job's units ran out
     *
     * @throws AiException
     */
    public function verifyJob(GenerationJob $job): bool
    {
        $size = max(1, min(20, (int) config('ai.generation.verify_batch_size')));
        $tried = [];
        $bank = null;
        while (true) {
            $batch = DraftQuestion::query()->where('job_id', $job->id)->whereNull('verify_verdict')
                ->whereIn('state', QuestionState::liveValues())->whereNotIn('id', $tried)
                ->orderBy('id')->limit($size)->get();
            if ($batch->isEmpty()) {
                break;
            }
            if (GenerationJob::query()->whereKey($job->id)->toBase()->value('status') === JobStatus::Cancelled->value) {
                return true;
            }
            if ($job->reserved_units <= 0) {
                $this->markUnverified($job, 'لم يتم التحقق من السؤال: انتهت وحدات الذكاء الاصطناعي لهذا الشهر.');

                return false;
            }
            array_push($tried, ...$batch->pluck('id')->all());

            [$sendable, $unsourced] = $batch->partition(fn (DraftQuestion $q) => $q->source_chunk_ref !== null);
            $updates = [];
            foreach ($unsourced as $q) {
                $updates[$q->id] = $this->row('unsupported', 0.0, ['لا يوجد مصدر يمكن التحقق منه.'], null, null, null, true);
            }
            $response = null;
            if ($sendable->isNotEmpty()) {
                $bank ??= $this->bank();
                $response = $this->ai->verify(['questions' => $sendable->map(fn (DraftQuestion $q) => $this->payload($q))->values()->all(), 'bank_questions' => $bank]);
                $updates += $this->results($sendable, $response['results']);
            }

            DB::transaction(function () use ($job, $batch, $response, $updates) {
                if ($response !== null) {
                    $this->budget->charge($job, UsageKind::Verify, "job:{$job->id}:verify:{$batch->first()->id}", $response['units_used'], $response['model'], $response['prompt_version']);
                    $job->prompt_versions = ['verify' => $response['prompt_version']] + (array) $job->prompt_versions;
                    GenerationJob::query()->whereKey($job->id)->update([
                        'model_verify' => mb_substr($response['model'], 0, 120),
                        'prompt_versions' => json_encode($job->prompt_versions),
                    ]);
                }
                BulkUpdate::byId('ai_draft_questions', $updates);
                GenerationJob::query()->whereKey($job->id)->increment('questions_verified', count($updates));
            });
        }
        // Questions the service gave no result for.
        $this->markUnverified($job, 'لم يتم التحقق من هذا السؤال. راجعه بنفسك.');

        return true;
    }

    /** Flags the job's questions still without a verdict. One statement. */
    public function markUnverified(GenerationJob $job, string $issue): void
    {
        DraftQuestion::query()->where('job_id', $job->id)->whereNull('verify_verdict')
            ->whereIn('state', QuestionState::liveValues())
            ->update(['flagged' => true, 'verify_issues' => json_encode([$issue], JSON_UNESCAPED_UNICODE)]);
    }

    private function payload(DraftQuestion $q): array
    {
        $rubric = is_array($q->rubric) && ! empty($q->rubric['criteria']) ? $q->rubric : null;

        return [
            'ref' => $q->ref(),
            'type' => $q->type->value,
            'prompt' => $q->prompt,
            'options' => $q->type->isWritten() ? null : array_values((array) $q->options),
            'correct_answer' => (string) ($q->type->isWritten() ? ($q->model_answer ?? $q->correct_answer) : $q->correct_answer),
            'rubric' => $rubric,
            'citation_chunk_ref' => (string) $q->source_chunk_ref,
            'citation_quote' => $q->source_quote !== null ? mb_substr($q->source_quote, 0, 1000) : null,
        ];
    }

    /**
     * @param  Collection<int, DraftQuestion>  $questions
     * @return array<int, array<string, scalar|null>>
     */
    private function results(Collection $questions, array $results): array
    {
        $byRef = $questions->keyBy(fn (DraftQuestion $q) => $q->ref());
        $updates = [];
        foreach ($results as $result) {
            $q = $byRef->get((string) $result['ref']);
            if ($q === null) {
                continue; // never trust a ref the service made up
            }
            $verdict = in_array($result['verdict'] ?? null, self::VERDICTS, true) ? $result['verdict'] : 'partial';
            $confidence = max(0.0, min(1.0, (float) ($result['confidence'] ?? 0)));
            $issues = array_values(array_map(fn ($i) => mb_substr((string) $i, 0, 500), array_slice(array_filter((array) ($result['issues'] ?? []), 'is_scalar'), 0, 10)));
            $duplicateOf = null;
            if (is_string($result['duplicate_of'] ?? null) && preg_match('/^d(\d+)$/', $result['duplicate_of'], $m) && (int) $m[1] !== $q->id) {
                $duplicateOf = (int) $m[1];
                $issues[] = 'السؤال مشابه جداً لسؤال آخر في المسودة.';
            }
            $flagged = (bool) ($result['flagged'] ?? false) || $verdict !== 'supported'
                || $confidence < 0.7 || $duplicateOf !== null || $q->flagged;
            $bankMatch = is_string($result['bank_match'] ?? null) ? mb_substr($result['bank_match'], 0, 40) : null;
            $fix = is_string($result['suggested_fix'] ?? null) ? mb_substr($result['suggested_fix'], 0, 2000) : null;
            $updates[$q->id] = $this->row($verdict, $confidence, $issues, $fix, $duplicateOf, $bankMatch, $flagged);
        }

        return $updates;
    }

    /** @return array<string, scalar|null> */
    private function row(string $verdict, float $confidence, array $issues, ?string $fix, ?int $duplicateOf, ?string $bankMatch, bool $flagged): array
    {
        return [
            'verify_verdict' => $verdict,
            'verify_confidence' => round($confidence, 3),
            'verify_issues' => json_encode($issues, JSON_UNESCAPED_UNICODE),
            'verify_suggested_fix' => $fix,
            'duplicate_of' => $duplicateOf,
            'bank_match' => $bankMatch,
            'flagged' => $flagged,
        ];
    }

    /** @return list<array{ref: string, prompt: string}> recent active questions of the center's bank (informational overlap) */
    private function bank(): array
    {
        $limit = (int) config('ai.generation.bank_questions');
        if ($limit <= 0) {
            return [];
        }

        return QuestionBankQuestion::query()->where('is_active', true)->latest('id')->limit($limit)->get(['id', 'prompt_html'])
            ->map(fn ($b) => ['ref' => 'b'.$b->id, 'prompt' => mb_substr(trim(html_entity_decode(strip_tags((string) $b->prompt_html))), 0, 4000)])
            ->filter(fn ($b) => $b['prompt'] !== '')->values()->all();
    }
}
