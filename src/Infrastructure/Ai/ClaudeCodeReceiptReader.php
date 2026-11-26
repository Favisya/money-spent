<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Application\Purchase\AiCredentials;
use App\Application\Purchase\AiProvider;
use App\Application\Purchase\ReadingContext;
use App\Application\Purchase\ReceiptInput;
use App\Application\Purchase\ReceiptReader;
use App\Application\Purchase\RecognizedReceipt;
use App\Application\UserError;
use JsonException;
use Psr\Log\LoggerInterface;

/**
 * Claude Code signed in to a Claude subscription, run headless: no tools, no MCP servers, no user settings or
 * hooks, and a structured answer against ReceiptPrompt::schema(). The photo goes in on stdin, never as a file.
 */
final readonly class ClaudeCodeReceiptReader implements ReceiptReader
{
    private const int DEPTH = 32;

    public function __construct(
        private LocalCli $cli,
        private LoggerInterface $logger,
    ) {
    }

    public function provider(): AiProvider
    {
        return AiProvider::ClaudeCode;
    }

    public function read(ReceiptInput $input, ReadingContext $context, AiCredentials $credentials): RecognizedReceipt
    {
        $arguments = [
            '--print',
            '--input-format', 'stream-json',
            '--output-format', 'stream-json',
            '--verbose',
            '--json-schema', json_encode(ReceiptPrompt::schema(), JSON_THROW_ON_ERROR),
            '--system-prompt', ReceiptPrompt::instructions($context),
            '--tools', '',
            '--strict-mcp-config',
            '--setting-sources', 'project',
            '--disable-slash-commands',
            '--no-session-persistence',
        ];

        if ($credentials->model !== '') {
            array_push($arguments, '--model', $credentials->model);
        }

        $tool   = (string) $this->provider()->tool();
        $output = $this->cli->inScratchDir(
            fn (string $dir): string => $this->cli->run($tool, $arguments, $dir, $this->message($input)),
        );

        return $this->answer($output);
    }

    private function message(ReceiptInput $input): string
    {
        $content = [];

        foreach ($input->images as $image) {
            $content[] = [
                'type'   => 'image',
                'source' => [
                    'type'       => 'base64',
                    'media_type' => $image->mediaType,
                    'data'       => base64_encode($image->bytes),
                ],
            ];
        }

        $content[] = ['type' => 'text', 'text' => ReceiptPrompt::request($input)];

        return json_encode(
            ['type' => 'user', 'message' => ['role' => 'user', 'content' => $content]],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ) . "\n";
    }

    private function answer(string $output): RecognizedReceipt
    {
        foreach (array_reverse(explode("\n", trim($output))) as $line) {
            $event = $this->decode($line);

            if (($event['type'] ?? null) !== 'result') {
                continue;
            }

            $structured = $event['structured_output'] ?? null;

            if (($event['is_error'] ?? true) !== false || !is_array($structured)) {
                $this->logger->error('Claude Code did not read the receipt', [
                    'subtype' => $event['subtype'] ?? null,
                    'result'  => is_string($event['result'] ?? null) ? mb_substr($event['result'], 0, 500) : null,
                ]);

                throw new UserError('Claude Code не смог прочитать чек — попробуй ещё раз.');
            }

            return RecognizedReceipt::fromArray($structured);
        }

        throw new UserError('ИИ ответил не по формату — попробуй ещё раз.');
    }

    /**
     * @return array<mixed>
     */
    private function decode(string $line): array
    {
        try {
            $event = json_decode($line, true, self::DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($event) ? $event : [];
    }
}
