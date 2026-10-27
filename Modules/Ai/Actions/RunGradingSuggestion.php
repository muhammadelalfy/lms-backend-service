<?php

declare(strict_types=1);

namespace Modules\Ai\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Ai\Enums\SuggestionStatus;
use Modules\Ai\Events\GradingSuggestionReady;
use Modules\Ai\Exceptions\AiException;
use Modules\Ai\Models\DraftQuestion;
use Modules\Ai\Models\GradingSuggestion;
use Modules\Ai\Models\QuestionRubric;
use Modules\Ai\Services\AiClient;
use Modules\Ai\Services\UnitBudget;
use Modules\Ai\Support\GradingNote;
use Modules\Ai\Support\JobError;
use Modules\Exams\Models\ExamQuestion;
use Modules\Exams\Models\ExamSessionAnswer;
use Modules\Exams\Models\ExamTemplate;

/**
 * One AI grading suggestion. Privacy (spec 3j): the AI service receives the question, its model
 * answer and rubric, the answer text and an opaque `answer_ref` only; never the student's name, id,
 * class, email or the session. The reply is advisory: the score is clamped to [0, marks] in quarter
 * marks, an answer suspected of prompt injection (or a low confidence) is marked for attention, and
 * the row is never changed again once ready. Units are charged once per answer to the monthly ledger.
 */
final class RunGradingSuggestion
{
    public function __construct(private readonly AiClient $client, private readonly UnitBudget $budget) {}

    /** @throws AiException when the service failed transiently and the job may try again */
    public function handle(int $suggestionId, bool $final = true): void
    {
        $suggestion = GradingSuggestion::query()->find($suggestionId);
        if ($suggestion === null || $suggestion->status !== SuggestionStatus::Queued) {
            return;
        }
        if ($this->budget->gradingStopped()) {
            $this->fail($suggestion->id, GradingNote::CAP_REACHED);

            return;
        }

        $question = ExamQuestion::query()->find($suggestion->question_id);
        $rubric = QuestionRubric::query()->where('question_id', $suggestion->question_id)->first();
        $answer = $suggestion->session_answer_id === null ? null : ExamSessionAnswer::query()->find($suggestion->session_answer_id);
        if ($question === null || $rubric === null || $answer === null) {
            $this->fail($suggestion->id, GradingNote::NO_RUBRIC);

            return;
        }

        try {
            $reply = $this->client->suggestGrade($this->payload($suggestion, $question, $rubric, (string) $answer->answer));
        } catch (AiException $e) {
            if ($e->transient && ! $final) {
                throw $e;
            }
            $this->fail($suggestion->id, $e->reason === JobError::AI_UNAVAILABLE ? GradingNote::AI_UNAVAILABLE : GradingNote::AI_REJECTED);

            return;
        }
        if ($reply['answer_ref'] !== $this->answerRef($suggestion)) {
            $this->fail($suggestion->id, GradingNote::AI_REJECTED);

            return;
        }

        $max = (float) $suggestion->max_score;
        $score = min($max, max(0.0, round($reply['suggested_score'] * 4) / 4));
        $confidence = min(1.0, max(0.0, $reply['confidence']));
        $attention = $reply['injection_suspected'] || $confidence < (float) config('ai.grading.attention_below');
        $owner = ExamTemplate::query()->whereKey($suggestion->template_id)->value('created_by');

        DB::transaction(function () use ($suggestion, $reply, $score, $confidence, $attention, $owner) {
            $updated = GradingSuggestion::query()->whereKey($suggestion->id)->where('status', SuggestionStatus::Queued->value)->update([
                'status' => SuggestionStatus::Ready->value,
                'failure_code' => null,
                'suggested_score' => $score,
                'criteria_scores' => json_encode($reply['criteria'], JSON_UNESCAPED_UNICODE),
                'feedback' => $reply['feedback'],
                'reasoning' => $reply['reasoning'],
                'confidence' => $confidence,
                'injection_suspected' => $reply['injection_suspected'],
                'needs_attention' => $attention,
                'prompt_version' => mb_substr($reply['prompt_version'], 0, 40),
                'model_id' => mb_substr($reply['model'], 0, 120),
                'units_used' => $reply['units_used'],
                'updated_at' => now(),
            ]);
            // Charged even if a parallel run saved first: the AI call happened. Once per answer.
            $this->budget->chargeGrading('grade:'.$suggestion->id, $owner === null ? null : (int) $owner, $reply['units_used'], $reply['model'], $reply['prompt_version']);
            if ($updated > 0) {
                DB::afterCommit(fn () => event(new GradingSuggestionReady($suggestion->id, $suggestion->session_id)));
            }
        });
    }

    /** No suggestion for this answer: the teacher grades it by hand. Only a still-queued item changes. */
    public function fail(int $suggestionId, string $code): void
    {
        GradingSuggestion::query()->whereKey($suggestionId)->where('status', SuggestionStatus::Queued->value)
            ->update(['status' => SuggestionStatus::Failed->value, 'failure_code' => $code, 'updated_at' => now()]);
    }

    /** @return array{question: array, answer_ref: string, answer_text: string, language: string} */
    private function payload(GradingSuggestion $suggestion, ExamQuestion $question, QuestionRubric $rubric, string $answerText): array
    {
        $criteria = [];
        foreach ((array) ($rubric->rubric['criteria'] ?? []) as $c) {
            if (is_array($c) && isset($c['id'], $c['text'], $c['marks'])) {
                $criteria[] = ['id' => (string) $c['id'], 'text' => (string) $c['text'], 'marks' => (float) $c['marks']];
            }
        }
        $alternatives = array_values(array_filter((array) ($rubric->rubric['accept_alternatives'] ?? []), 'is_string'));
        $prompt = trim(html_entity_decode(strip_tags((string) $question->prompt_html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return [
            'question' => [
                'type' => $this->type($rubric),
                'prompt' => mb_substr($prompt !== '' ? $prompt : '-', 0, 4000),
                'marks' => (float) $question->points,
                'model_answer' => $rubric->model_answer === null ? null : mb_substr($rubric->model_answer, 0, 8000),
                'rubric' => $criteria === [] ? null : ['criteria' => array_slice($criteria, 0, 10), 'accept_alternatives' => array_slice($alternatives, 0, 20)],
            ],
            'answer_ref' => $this->answerRef($suggestion),
            'answer_text' => mb_substr($answerText, 0, (int) config('ai.grading.max_answer_chars')),
            'language' => 'ar',
        ];
    }

    /** Opaque: the grading item's own id, which says nothing about the student. */
    private function answerRef(GradingSuggestion $suggestion): string
    {
        return 'a_'.$suggestion->id;
    }

    /** The written type the AI wrote it as (short answer, fill-in, essay); a manual exam's question is an essay. */
    private function type(QuestionRubric $rubric): string
    {
        if ($rubric->ai_draft_question_id === null) {
            return 'essay';
        }
        $type = DraftQuestion::query()->whereKey($rubric->ai_draft_question_id)->value('type');

        return in_array($type, ['short_answer', 'fill_blank', 'essay'], true) ? $type : 'essay';
    }
}
