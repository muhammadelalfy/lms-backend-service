<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Ai\DTOs\ExtractedMaterial;
use Modules\Ai\DTOs\ExtractedSection;
use Modules\Ai\DTOs\IngestResult;
use Modules\Ai\Exceptions\AiException;
use Modules\Ai\Support\FailureReason;
use Modules\Ai\Support\JobError;

/**
 * The only door to lms-ai-service. Bearer service token, the school id in X-Center-Id (taken from the
 * tenant, never from a request), timeouts everywhere. Idempotent calls (ingest, delete) retry once on
 * a transient failure; extraction does not retry here because its upload stream cannot be replayed,
 * its job retries instead. Every failure becomes an AiException with an Arabic message; callers
 * run it from queued jobs so a request is never held by the AI service.
 */
class AiClient
{
    private const TRANSIENT = [408, 425, 429, 500, 502, 503, 504];

    /**
     * @param  resource  $stream  the file, opened for reading
     *
     * @throws AiException
     */
    public function extract($stream, string $filename, string $kind, string $excelMode): ExtractedMaterial
    {
        $response = $this->send(
            fn () => $this->http((float) config('services.ai.extract_timeout'))
                ->attach('file', $stream, $filename)
                ->post('/v1/materials/extract', ['kind' => $kind, 'excel_mode' => $excelMode])
        );
        $body = $response->json();
        if (! is_array($body) || ! is_array($body['sections'] ?? null)) {
            throw AiException::unavailable($response->status(), transient: false);
        }

        $sections = [];
        foreach ($body['sections'] as $section) {
            if (! is_array($section) || ! is_string($section['text'] ?? null)) {
                throw AiException::unavailable($response->status(), transient: false);
            }
            $sections[] = new ExtractedSection(
                (string) ($section['kind'] ?? 'section'),
                (string) ($section['label'] ?? ''),
                (int) ($section['ordinal'] ?? count($sections) + 1),
                $section['text'],
            );
        }

        return new ExtractedMaterial(
            isset($body['pages']) ? (int) $body['pages'] : null,
            (int) ($body['char_count'] ?? array_sum(array_map(fn (ExtractedSection $s) => $s->charCount(), $sections))),
            (bool) ($body['needs_text_review'] ?? false),
            array_values(array_filter((array) ($body['warnings'] ?? []), 'is_string')),
            $sections,
        );
    }

    /**
     * @param  list<array{id: int, kind: string, label: string, text: string}>  $sections
     *
     * @throws AiException
     */
    public function ingest(int $materialId, int $version, string $embedModel, array $sections): IngestResult
    {
        $response = $this->send(
            fn () => $this->http((float) config('services.ai.extract_timeout'), retry: true)
                ->post("/v1/materials/{$materialId}/ingest", ['version' => $version, 'embed_model' => $embedModel, 'sections' => $sections])
        );
        $body = $response->json();
        if (! is_array($body) || ! is_array($body['chunk_refs'] ?? null)) {
            throw AiException::unavailable($response->status(), transient: false);
        }

        $refs = [];
        foreach ($body['chunk_refs'] as $ref) {
            if (is_array($ref) && isset($ref['section_id'], $ref['chunk_ref'])) {
                $refs[] = ['section_id' => (int) $ref['section_id'], 'chunk_ref' => (string) $ref['chunk_ref'], 'ordinal' => (int) ($ref['ordinal'] ?? 0)];
            }
        }

        return new IngestResult((int) ($body['version'] ?? $version), (int) ($body['chunks'] ?? count($refs)), $refs);
    }

    /**
     * Removes a material's chunks and vectors; a material the service no longer knows counts as deleted.
     *
     * @return int how many chunks were removed
     *
     * @throws AiException
     */
    public function deleteMaterial(int $materialId): int
    {
        $response = $this->send(
            fn () => $this->http((float) config('services.ai.timeout'), retry: true)->delete("/v1/materials/{$materialId}"),
            tolerate: [404],
        );

        return (int) ($response->json('deleted_chunks') ?? 0);
    }

    /**
     * The cost of a blueprint on the given materials, without any LLM call. Safe to retry.
     *
     * @param  array{blueprint: array, material_ids: list<string>, section_ids: list<int>|null}  $payload
     * @return array{units: int, input_tokens: int, output_tokens: int, breakdown: array{generate: int, verify: int}, supported_questions: int, warnings: list<string>}
     *
     * @throws AiException
     */
    public function estimate(array $payload): array
    {
        $body = $this->examCall('/v1/exams/estimate', $payload, (float) config('services.ai.timeout'), retry: true);
        if (! isset($body['units'])) {
            throw $this->badAnswer();
        }

        return [
            'units' => (int) $body['units'],
            'input_tokens' => (int) ($body['input_tokens'] ?? 0),
            'output_tokens' => (int) ($body['output_tokens'] ?? 0),
            'breakdown' => ['generate' => (int) ($body['breakdown']['generate'] ?? 0), 'verify' => (int) ($body['breakdown']['verify'] ?? 0)],
            'supported_questions' => (int) ($body['supported_questions'] ?? 0),
            'warnings' => array_values(array_filter((array) ($body['warnings'] ?? []), 'is_string')),
        ];
    }

    /**
     * Writes up to 10 questions. Costs units, so it is never retried here: the queued job retries it.
     *
     * @return array{prompt_version: string, model: string, units_used: int, supported: bool, partial: bool, questions: list<array>, shortfall: list<array>}
     *
     * @throws AiException
     */
    public function generate(array $payload): array
    {
        $body = $this->examCall('/v1/exams/generate', $payload, (float) config('ai.generation.generate_timeout'));
        if (! is_array($body['questions'] ?? null)) {
            throw $this->badAnswer();
        }

        return [
            'prompt_version' => (string) ($body['prompt_version'] ?? ''),
            'model' => (string) ($body['model'] ?? ''),
            'units_used' => max(0, (int) ($body['units_used'] ?? 0)),
            'supported' => (bool) ($body['supported'] ?? false),
            'partial' => (bool) ($body['partial'] ?? false),
            'questions' => array_values(array_filter($body['questions'], fn ($q) => is_array($q) && isset($q['type'], $q['prompt']))),
            'shortfall' => array_values(array_filter((array) ($body['shortfall'] ?? []), 'is_array')),
        ];
    }

    /**
     * The independent verification pass over up to 20 questions (chunk text is looked up by the service).
     *
     * @return array{prompt_version: string, model: string, units_used: int, results: list<array>}
     *
     * @throws AiException
     */
    public function verify(array $payload): array
    {
        $body = $this->examCall('/v1/exams/verify', $payload, (float) config('ai.generation.generate_timeout'));
        if (! is_array($body['results'] ?? null)) {
            throw $this->badAnswer();
        }

        return [
            'prompt_version' => (string) ($body['prompt_version'] ?? ''),
            'model' => (string) ($body['model'] ?? ''),
            'units_used' => max(0, (int) ($body['units_used'] ?? 0)),
            'results' => array_values(array_filter($body['results'], fn ($r) => is_array($r) && isset($r['ref']))),
        ];
    }

    /**
     * Suggests a grade for one written answer. The payload holds only the question, model answer,
     * rubric, the answer text and an opaque `answer_ref` (no student identity; the service refuses any
     * other field). Costs units, so it is never retried here: the queued job retries it.
     *
     * @param  array{question: array, answer_ref: string, answer_text: string, language: string}  $payload
     * @return array{prompt_version: string, model: string, answer_ref: string, suggested_score: float, criteria: list<array{id: string, score: float, reason: string}>, feedback: string, reasoning: string, confidence: float, injection_suspected: bool, units_used: int}
     *
     * @throws AiException
     */
    public function suggestGrade(array $payload): array
    {
        $body = $this->examCall('/v1/grading/suggest', $payload, (float) config('ai.grading.timeout'));
        if (! is_numeric($body['suggested_score'] ?? null) || ! is_numeric($body['confidence'] ?? null)) {
            throw $this->badAnswer();
        }
        $criteria = [];
        foreach ((array) ($body['criteria'] ?? []) as $c) {
            if (is_array($c) && isset($c['id']) && is_numeric($c['score'] ?? null)) {
                $criteria[] = ['id' => mb_substr((string) $c['id'], 0, 20), 'score' => (float) $c['score'], 'reason' => mb_substr((string) ($c['reason'] ?? ''), 0, 1000)];
            }
        }

        return [
            'prompt_version' => (string) ($body['prompt_version'] ?? ''),
            'model' => (string) ($body['model'] ?? ''),
            'answer_ref' => (string) ($body['answer_ref'] ?? ''),
            'suggested_score' => (float) $body['suggested_score'],
            'criteria' => array_slice($criteria, 0, 10),
            'feedback' => mb_substr((string) ($body['feedback'] ?? ''), 0, 3000),
            'reasoning' => mb_substr((string) ($body['reasoning'] ?? ''), 0, 3000),
            'confidence' => (float) $body['confidence'],
            'injection_suspected' => (bool) ($body['injection_suspected'] ?? false),
            'units_used' => max(0, (int) ($body['units_used'] ?? 0)),
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AiException
     */
    private function examCall(string $path, array $payload, float $timeout, bool $retry = false): array
    {
        try {
            $response = $this->http($timeout, $retry)->post($path, $payload);
        } catch (ConnectionException) {
            throw new AiException(JobError::AI_UNAVAILABLE, true, null, JobError::message(JobError::AI_UNAVAILABLE));
        }
        $status = $response->status();
        if (! $response->successful()) {
            $code = $response->json('code');
            $message = $response->json('message');
            logger()->warning('AI service refused an exam call', ['path' => $path, 'status' => $status, 'code' => is_string($code) ? $code : null]);

            if (in_array($status, self::TRANSIENT, true) || $status === 401 || $status === 403) {
                throw new AiException(JobError::AI_UNAVAILABLE, in_array($status, self::TRANSIENT, true), $status, JobError::message(JobError::AI_UNAVAILABLE));
            }
            $code = is_string($code) && preg_match('/^[a-z_]{1,40}$/', $code) ? $code : JobError::AI_REJECTED;

            throw new AiException($code, false, $status, is_string($message) && $message !== '' ? mb_substr($message, 0, 300) : JobError::message($code));
        }
        $body = $response->json();

        return is_array($body) ? $body : throw $this->badAnswer();
    }

    private function badAnswer(): AiException
    {
        return new AiException(JobError::AI_UNAVAILABLE, false, null, JobError::message(JobError::AI_UNAVAILABLE));
    }

    private function http(float $timeout, bool $retry = false): PendingRequest
    {
        $url = config('services.ai.url');
        $center = tenant()?->getTenantKey();
        if (blank($url) || blank(config('services.ai.token'))) {
            throw AiException::unavailable(transient: false);
        }
        if ($center === null) {
            throw new \LogicException('The AI service is only called inside a school.');
        }

        $request = Http::baseUrl(rtrim((string) $url, '/'))
            ->withToken((string) config('services.ai.token'))
            ->withHeaders(['X-Center-Id' => (string) $center])
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout($timeout);

        return $retry
            ? $request->retry(2, 300, fn (\Throwable $e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && in_array($e->response->status(), self::TRANSIENT, true)), throw: false)
            : $request;
    }

    /**
     * @param  list<int>  $tolerate  statuses that are not errors for this call
     *
     * @throws AiException
     */
    private function send(callable $call, array $tolerate = []): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException) {
            throw AiException::unavailable();
        }
        if ($response->successful() || in_array($response->status(), $tolerate, true)) {
            return $response;
        }

        throw $this->mapFailure($response);
    }

    private function mapFailure(Response $response): AiException
    {
        $status = $response->status();
        $code = $response->json('code');
        $message = $response->json('message');

        // Logged without any content: only ids and numbers.
        logger()->warning('AI service refused a call', ['status' => $status, 'code' => is_string($code) ? $code : null]);

        return match (true) {
            in_array($status, self::TRANSIENT, true) => AiException::unavailable($status),
            $status === 401 || $status === 403 => AiException::unavailable($status, transient: false),
            $status === 413 => new AiException(FailureReason::FILE_TOO_LARGE, false, $status),
            $status === 409 => new AiException('stale_version', false, $status, 'نسخة المادة قديمة. أعد المحاولة.'),
            is_string($code) && FailureReason::known($code) => new AiException($code, false, $status),
            $status === 422 && is_string($message) && $message !== '' => new AiException(FailureReason::CORRUPT, false, $status, $message),
            default => new AiException(FailureReason::CORRUPT, false, $status),
        };
    }
}
