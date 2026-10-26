<?php

declare(strict_types=1);

namespace Modules\Ai\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Modules\Ai\Exceptions\AiException;
use Modules\Ai\Services\AiClient;
use Modules\Ai\Tests\Concerns\BuildsMaterials;
use Tests\TestCase;

class AiClientTest extends TestCase
{
    use BuildsMaterials;

    private AiClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableAi();
        $this->client = new AiClient;
    }

    private function stream()
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, '%PDF-1.4');
        rewind($stream);

        return $stream;
    }

    private function failure(callable $call): AiException
    {
        try {
            $call();
        } catch (AiException $e) {
            return $e;
        }
        $this->fail('Expected an AiException.');
    }

    public function test_extract_sends_the_token_and_the_school_and_parses_the_contract(): void
    {
        $this->fakeAiService();

        $result = $this->client->extract($this->stream(), 'ملخص.pdf', 'pdf', 'auto');

        $this->assertSame(2, $result->pages);
        $this->assertCount(2, $result->sections);
        $this->assertSame('صفحة 1', $result->sections[0]->label);
        Http::assertSent(fn ($r) => $r->url() === 'http://ai.test/v1/materials/extract'
            && $r->hasHeader('Authorization', 'Bearer service-token')
            && $r->hasHeader('X-Center-Id', 'demo')
            && $r->isMultipart());
    }

    public function test_the_center_header_is_the_current_school_never_something_from_the_request(): void
    {
        $this->fakeAiService();
        $beta = $this->openSchool('beta');
        $this->assertSame('beta', $beta->id);

        $this->client->deleteMaterial(5);

        Http::assertSent(fn ($r) => $r->hasHeader('X-Center-Id', 'beta'));
    }

    public function test_transient_failures_of_an_idempotent_call_are_retried(): void
    {
        Http::fake(['ai.test/v1/materials/*' => Http::sequence()->push([], 503)->push(['deleted_chunks' => 4])]);

        $this->assertSame(4, $this->client->deleteMaterial(7));
        Http::assertSentCount(2);
    }

    public function test_extract_is_not_retried_by_the_client(): void
    {
        Http::fake(['ai.test/*' => Http::response([], 503)]);

        $e = $this->failure(fn () => $this->client->extract($this->stream(), 'a.pdf', 'pdf', 'auto'));

        $this->assertTrue($e->transient);
        Http::assertSentCount(1);
    }

    public function test_client_errors_are_not_retried(): void
    {
        Http::fake(['ai.test/*' => Http::response(['message' => 'x', 'code' => 'password_protected'], 422)]);

        $e = $this->failure(fn () => $this->client->ingest(1, 1, 'bge-m3', []));

        $this->assertFalse($e->transient);
        $this->assertSame('password_protected', $e->reason);
        Http::assertSentCount(1);
    }

    public function test_errors_map_to_arabic_messages(): void
    {
        $cases = [429 => true, 502 => true, 503 => true, 401 => false, 413 => false, 409 => false, 422 => false];
        foreach ($cases as $status => $transient) {
            Http::swap(new Factory); // a fresh fake: the first matching stub wins
            Http::fake(['ai.test/*' => Http::response([], $status)]);
            $e = $this->failure(fn () => $this->client->extract($this->stream(), 'a.pdf', 'pdf', 'auto'));
            $this->assertSame($transient, $e->transient, (string) $status);
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $e->getMessage(), (string) $status);
        }
        $this->assertSame('المساعد غير متاح الآن. أعد المحاولة بعد قليل.', $e::unavailable()->getMessage());
    }

    public function test_a_timeout_or_refused_connection_is_a_transient_unavailable(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $e = $this->failure(fn () => $this->client->extract($this->stream(), 'a.pdf', 'pdf', 'auto'));

        $this->assertSame('ai_unavailable', $e->reason);
        $this->assertTrue($e->transient);
    }

    public function test_an_unconfigured_service_fails_fast_without_a_call(): void
    {
        config(['services.ai.url' => '']);
        Http::fake();

        $e = $this->failure(fn () => $this->client->deleteMaterial(1));

        $this->assertSame('ai_unavailable', $e->reason);
        $this->assertFalse($e->transient);
        Http::assertNothingSent();
    }

    public function test_a_malformed_answer_is_an_error_not_a_crash(): void
    {
        Http::fake(['ai.test/*' => Http::response(['sections' => [['label' => 'x']]])]);

        $e = $this->failure(fn () => $this->client->extract($this->stream(), 'a.pdf', 'pdf', 'auto'));

        $this->assertSame('ai_unavailable', $e->reason);
    }

    public function test_deleting_a_material_the_service_does_not_know_is_fine(): void
    {
        Http::fake(['ai.test/*' => Http::response([], 404)]);

        $this->assertSame(0, $this->client->deleteMaterial(9));
    }
}
