<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Ai;

use App\Application\Purchase\AiCredentials;
use App\Application\Purchase\ReadingContext;
use App\Application\Purchase\ReceiptImage;
use App\Application\Purchase\ReceiptInput;
use App\Application\UserError;
use App\Domain\Money\Currency;
use App\Infrastructure\Ai\ClaudeReceiptReader;
use App\Infrastructure\Ai\OpenAiReceiptReader;
use App\Infrastructure\Ai\ReceiptPrompt;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The SDK adapters against canned HTTP answers: what is sent, and how answers and failures come back.
 */
final class ReceiptReadersTest extends TestCase
{
    public const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASU'
        . 'VORK5CYII=';

    private const string ANSWER = '{"store":"SAS","date":"2026-11-09","currency":"AMD","total":"1200",'
        . '"items":[{"name":"Молоко","quantity":"2","unit":"pcs","unit_price":"600","amount":"1200"}]}';

    private const string CLAUDE     = 'claude-opus-5-5';
    private const string OPENAI_KEY = 'sk-proj-test-0000000000000';
    private const string BAD_KEY    = 'sk-bad-key-000000000000';

    /** @var list<array{method: string, url: string, headers: array<string, mixed>, body: string}> */
    private array $requests = [];

    public function testClaudeSendsThePhotoAndReadsTheStructuredAnswer(): void
    {
        $reader = new ClaudeReceiptReader($this->transport(new MockResponse((string) json_encode([
            'id'            => 'msg_1',
            'type'          => 'message',
            'role'          => 'assistant',
            'model'         => 'claude-opus-5-5',
            'content'       => [['type' => 'text', 'text' => self::ANSWER]],
            'stop_reason'   => 'end_turn',
            'stop_sequence' => null,
            'usage'         => ['input_tokens' => 100, 'output_tokens' => 50],
        ]), ['response_headers' => ['content-type' => 'application/json']])), new NullLogger());

        $receipt = $reader->read($this->photo(), $this->context(), $this->claudeKey());

        self::assertSame('SAS', $receipt->store);
        self::assertSame('Молоко', $receipt->lines[0]->name);

        $request = $this->requests[0];
        $body    = json_decode($request['body'], true);
        self::assertIsArray($body);
        self::assertSame('https://api.anthropic.com/v1/messages', $request['url']);
        self::assertSame('claude-opus-5-5', $body['model']);
        self::assertSame('default', $body['fallbacks']);
        self::assertSame('json_schema', $body['output_config']['format']['type']);
        self::assertSame(ReceiptPrompt::schema(), $body['output_config']['format']['schema']);
        self::assertSame('image/png', $body['messages'][0]['content'][0]['source']['media_type']);
        self::assertSame(self::PNG, $body['messages'][0]['content'][0]['source']['data']);
        self::assertStringContainsString('server-side-fallback-2026-07-01', $this->header($request, 'anthropic-beta'));
        self::assertSame('Bearer sk-ant-test-key-0000000000', $this->header($request, 'authorization'));
    }

    public function testClaudeRejectedKeyIsExplained(): void
    {
        $reader = new ClaudeReceiptReader($this->transport(new MockResponse(
            '{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"}}',
            ['http_code' => 401, 'response_headers' => ['content-type' => 'application/json']],
        )), new NullLogger());

        $this->expectException(UserError::class);
        $this->expectExceptionMessage('Ключ Claude не подошёл');

        $reader->read(ReceiptInput::text('хлеб 450'), $this->context(), new AiCredentials(self::BAD_KEY, self::CLAUDE));
    }

    public function testClaudeRefusalIsExplained(): void
    {
        $reader = new ClaudeReceiptReader($this->transport(new MockResponse((string) json_encode([
            'id'            => 'msg_2',
            'type'          => 'message',
            'role'          => 'assistant',
            'model'         => 'claude-opus-5-5',
            'content'       => [],
            'stop_reason'   => 'refusal',
            'stop_sequence' => null,
            'usage'         => ['input_tokens' => 1, 'output_tokens' => 1],
        ]), ['response_headers' => ['content-type' => 'application/json']])), new NullLogger());

        $this->expectException(UserError::class);
        $this->expectExceptionMessage('отказалась');

        $reader->read(ReceiptInput::text('хлеб 450'), $this->context(), $this->claudeKey());
    }

    public function testOpenAiSendsTheListAndReadsTheStructuredAnswer(): void
    {
        $reader = new OpenAiReceiptReader($this->transport(new MockResponse((string) json_encode([
            'id'                 => 'resp_1',
            'object'             => 'response',
            'created_at'         => 1_794_300_000,
            'status'             => 'completed',
            'error'              => null,
            'incomplete_details' => null,
            'instructions'       => 'x',
            'max_output_tokens'  => 16000,
            'model'              => 'gpt-5.1',
            'output'             => [[
                'type'    => 'message',
                'id'      => 'msg_1',
                'status'  => 'completed',
                'role'    => 'assistant',
                'content' => [['type' => 'output_text', 'text' => self::ANSWER, 'annotations' => []]],
            ]],
            'parallel_tool_calls'  => true,
            'previous_response_id' => null,
            'reasoning'            => null,
            'store'                => true,
            'temperature'          => 1,
            'text'                 => ['format' => ['type' => 'text']],
            'tool_choice'          => 'auto',
            'tools'                => [],
            'top_p'                => 1,
            'truncation'           => 'disabled',
            'usage'                => null,
            'user'                 => null,
            'metadata'             => [],
        ]), ['response_headers' => ['content-type' => 'application/json']])), new NullLogger());

        $receipt = $reader->read(
            ReceiptInput::text('молоко 2 шт 1200'),
            $this->context(),
            new AiCredentials(self::OPENAI_KEY, 'gpt-5.1'),
        );

        self::assertSame('2', $receipt->lines[0]->quantity);

        $body = json_decode($this->requests[0]['body'], true);
        self::assertIsArray($body);
        self::assertStringEndsWith('/v1/responses', $this->requests[0]['url']);
        self::assertSame('gpt-5.1', $body['model']);
        self::assertTrue($body['text']['format']['strict']);
        self::assertStringContainsString('молоко 2 шт 1200', $body['input'][0]['content'][0]['text']);
        self::assertSame('Bearer ' . self::OPENAI_KEY, $this->header($this->requests[0], 'authorization'));
    }

    public function testOpenAiRejectedKeyIsExplained(): void
    {
        $reader = new OpenAiReceiptReader($this->transport(new MockResponse(
            '{"error":{"message":"Incorrect API key provided","type":"invalid_request_error",'
            . '"code":"invalid_api_key"}}',
            ['http_code' => 401, 'response_headers' => ['content-type' => 'application/json']],
        )), new NullLogger());

        $this->expectException(UserError::class);
        $this->expectExceptionMessage('Ключ OpenAI не подошёл');

        $reader->read(ReceiptInput::text('хлеб 450'), $this->context(), new AiCredentials(self::BAD_KEY, 'gpt-5.1'));
    }

    public function testSchemaIsStrict(): void
    {
        $check = static function (array $node) use (&$check): void {
            if (($node['type'] ?? null) === 'object') {
                self::assertFalse($node['additionalProperties']);
                self::assertSame(array_keys($node['properties']), $node['required']);

                foreach ($node['properties'] as $property) {
                    $check($property);
                }
            }

            if (($node['type'] ?? null) === 'array') {
                $check($node['items']);
            }
        };

        $check(ReceiptPrompt::schema());
    }

    private function transport(MockResponse $response): Psr18Client
    {
        $record = function (string $method, string $url, array $options) use ($response): MockResponse {
            $this->requests[] = [
                'method'  => $method,
                'url'     => $url,
                'headers' => $options['normalized_headers'] ?? [],
                'body'    => is_string($options['body'] ?? null) ? $options['body'] : '',
            ];

            return $response;
        };

        return new Psr18Client(new MockHttpClient($record));
    }

    /**
     * @param array{headers: array<string, mixed>} $request
     */
    private function header(array $request, string $name): string
    {
        $values = $request['headers'][$name] ?? [];

        return is_array($values) && is_string($values[0] ?? null)
            ? (string) preg_replace('/^[^:]+:\s*/', '', $values[0])
            : '';
    }

    private function claudeKey(): AiCredentials
    {
        return new AiCredentials('sk-ant-test-key-0000000000', self::CLAUDE);
    }

    private function photo(): ReceiptInput
    {
        return ReceiptInput::photo(ReceiptImage::fromBytes((string) base64_decode(self::PNG, true)));
    }

    private function context(): ReadingContext
    {
        $amd = new Currency('AMD', 0);

        return new ReadingContext(new DateTimeImmutable('2026-11-10'), $amd, ['AMD' => $amd], 'ru');
    }
}
