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
