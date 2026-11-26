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
use Symfony\Component\Filesystem\Filesystem;

/**
 * Codex signed in to ChatGPT, run as "codex exec" in a read-only sandbox inside a throwaway folder that holds only
 * the photo, the answer schema and the answer.
 */
final readonly class CodexReceiptReader implements ReceiptReader
{
    private const string SCHEMA    = 'schema.json';
    private const string ANSWER    = 'answer.json';
    private const array EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    public function __construct(
        private LocalCli $cli,
        private Filesystem $files,
    ) {
    }

    public function provider(): AiProvider
    {
        return AiProvider::Codex;
    }

    public function read(ReceiptInput $input, ReadingContext $context, AiCredentials $credentials): RecognizedReceipt
    {
        $prompt = ReceiptPrompt::instructions($context) . "\n\n" . ReceiptPrompt::request($input);

        return $this->cli->inScratchDir(
            fn (string $dir): RecognizedReceipt => $this->readIn($dir, $input, $credentials, $prompt),
        );
    }

    private function readIn(
        string $dir,
        ReceiptInput $input,
        AiCredentials $credentials,
        string $prompt,
    ): RecognizedReceipt {
        $this->files->dumpFile($dir . '/' . self::SCHEMA, json_encode(ReceiptPrompt::schema(), JSON_THROW_ON_ERROR));

        $arguments = [
            'exec',
            '--skip-git-repo-check',
            '--ephemeral',
            '--ignore-user-config',
            '--ignore-rules',
            '--sandbox', 'read-only',
            '--color', 'never',
            '--cd', $dir,
            '--output-schema', $dir . '/' . self::SCHEMA,
        ];

        if ($input->image !== null) {
            $photo = $dir . '/receipt.' . self::EXTENSIONS[$input->image->mediaType];
            $this->files->dumpFile($photo, $input->image->bytes);
            array_push($arguments, '--image', $photo);
        }

        if ($credentials->model !== '') {
            array_push($arguments, '--model', $credentials->model);
        }

        array_push($arguments, '--output-last-message', $dir . '/' . self::ANSWER, '-');

        $this->cli->run((string) $this->provider()->tool(), $arguments, $dir, $prompt);

        $answer = $dir . '/' . self::ANSWER;

        if (!is_file($answer)) {
            throw new UserError('ИИ ответил не по формату — попробуй ещё раз.');
        }

        return AnswerJson::decode($this->files->readFile($answer));
    }
}
