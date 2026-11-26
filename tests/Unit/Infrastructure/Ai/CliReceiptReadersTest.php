<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Ai;

use App\Application\Purchase\AiCredentials;
use App\Application\Purchase\ReadingContext;
use App\Application\Purchase\ReceiptImage;
use App\Application\Purchase\ReceiptInput;
use App\Application\UserError;
use App\Domain\Money\Currency;
use App\Infrastructure\Ai\ClaudeCodeReceiptReader;
use App\Infrastructure\Ai\CodexReceiptReader;
use App\Infrastructure\Ai\LocalCli;
use App\Infrastructure\Ai\ReceiptPrompt;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Claude Code and Codex against stand-in shell scripts that record how they were called and print a canned answer.
 */
final class CliReceiptReadersTest extends TestCase
{
    private const string ANSWER = '{"store":"SAS","date":"2026-11-09","currency":"AMD","total":"1200",'
        . '"items":[{"name":"Milk","quantity":"2","unit":"pcs","unit_price":"600","amount":"1200"}]}';

    private const string SECRET = 'MONEY_SPENT_TEST_SECRET';

    private Filesystem $files;
    private string $bin;
    private string $log;

    protected function setUp(): void
    {
        $this->files = new Filesystem();
        $root        = sys_get_temp_dir() . '/cli-test-' . bin2hex(random_bytes(6));
        $this->bin   = $root . '/bin';
        $this->log   = $root . '/log';
        $this->files->mkdir([$this->bin, $this->log]);
        putenv(self::SECRET . '=leaked');
    }

    protected function tearDown(): void
    {
        putenv(self::SECRET);
        $this->files->remove(dirname($this->bin));
    }

    public function testClaudeCodeGetsThePhotoOnStdinWithNoToolsAndNoSecrets(): void
    {
        $this->tool('claude', $this->claudeOutput(['is_error' => false, 'structured_output' => $this->answer()]));

        $receipt = $this->claudeCode()->read($this->photo(), $this->context('en'), new AiCredentials('', 'opus'));

        self::assertSame('SAS', $receipt->store);
        self::assertSame('Milk', $receipt->lines[0]->name);

        $arguments = $this->arguments();
        self::assertSame('', $arguments[(int) array_search('--tools', $arguments, true) + 1]);
        self::assertSame('opus', $arguments[(int) array_search('--model', $arguments, true) + 1]);
        self::assertContains('--strict-mcp-config', $arguments);
        $instructions = $arguments[(int) array_search('--system-prompt', $arguments, true) + 1];
        self::assertStringContainsString('into English', $instructions);

        $message = json_decode($this->logged('stdin'), true);
        self::assertIsArray($message);
        self::assertSame('image', $message['message']['content'][0]['type']);
        self::assertSame(ReceiptReadersTest::PNG, $message['message']['content'][0]['source']['data']);

        self::assertStringNotContainsString(self::SECRET, $this->logged('env'));
        self::assertStringContainsString('PATH=' . $this->bin . ':', $this->logged('env'));
        self::assertDirectoryDoesNotExist(trim($this->logged('cwd')));
    }

    public function testClaudeCodeWithoutModelUsesItsOwnDefault(): void
    {
        $this->tool('claude', $this->claudeOutput(['is_error' => false, 'structured_output' => $this->answer()]));

        $this->claudeCode()->read(ReceiptInput::text('milk 1200'), $this->context('ru'), new AiCredentials('', ''));

        self::assertNotContains('--model', $this->arguments());
    }

    public function testToolGetsTheAccountNameWhenTheAppStartsWithoutOne(): void
    {
        $this->tool('claude', $this->claudeOutput(['is_error' => false, 'structured_output' => $this->answer()]));
        $user = getenv('USER');
        putenv('USER');

        try {
            $this->claudeCode()->read(ReceiptInput::text('milk 1200'), $this->context('ru'), new AiCredentials('', ''));
        } finally {
            putenv(is_string($user) ? 'USER=' . $user : 'USER');
        }

        self::assertMatchesRegularExpression('/^USER=\S+$/m', $this->logged('env'));
        self::assertMatchesRegularExpression('/^LOGNAME=\S+$/m', $this->logged('env'));
    }

    public function testClaudeCodeErrorResultIsExplained(): void
    {
        $this->tool('claude', $this->claudeOutput(['is_error' => true, 'result' => 'Not logged in']));

        $this->expectException(UserError::class);
        $this->expectExceptionMessage('Claude Code не смог прочитать чек');

        $this->claudeCode()->read($this->photo(), $this->context('ru'), new AiCredentials('', ''));
    }

    public function testFailingToolIsExplained(): void
    {
        $this->tool('claude', 'boom', 1);

        $this->expectException(UserError::class);
        $this->expectExceptionMessage('claude завершился с ошибкой');

        $this->claudeCode()->read($this->photo(), $this->context('ru'), new AiCredentials('', ''));
    }

    public function testMissingToolIsExplained(): void
    {
        $this->expectException(UserError::class);
        $this->expectExceptionMessage('no-such-receipt-tool не найден на этом компьютере');

        $this->cli()->run('no-such-receipt-tool', [], sys_get_temp_dir(), '');
    }

    public function testCodexGetsThePhotoAsAFileInAReadOnlySandbox(): void
    {
        $this->tool('codex', self::ANSWER);

        $receipt = $this->codex()->read($this->photo(), $this->context('ru'), new AiCredentials('', ''));

        self::assertSame('SAS', $receipt->store);
        self::assertSame(base64_decode(ReceiptReadersTest::PNG, true), $this->logged('image-1'));
        self::assertFileDoesNotExist($this->log . '/image-2');

        $arguments = $this->arguments();
        self::assertSame('exec', $arguments[0]);
        self::assertSame('read-only', $arguments[(int) array_search('--sandbox', $arguments, true) + 1]);
        self::assertSame('-', $arguments[array_key_last($arguments)]);
        self::assertNotContains('--model', $arguments);
        self::assertStringContainsString('Read the positions of this receipt or order.', $this->logged('stdin'));
        self::assertStringNotContainsString(self::SECRET, $this->logged('env'));
        self::assertDirectoryDoesNotExist(trim($this->logged('cwd')));
    }

    public function testSeveralScreenshotsAreReadAsOneOrder(): void
    {
        $this->tool('codex', self::ANSWER);
        $this->codex()->read($this->photos(3), $this->context('ru'), new AiCredentials('', ''));

        self::assertFileExists($this->log . '/image-3');
        self::assertStringContainsString('These 3 images are consecutive parts', $this->logged('stdin'));

        $this->tool('claude', $this->claudeOutput(['is_error' => false, 'structured_output' => $this->answer()]));
        $this->claudeCode()->read($this->photos(2), $this->context('ru'), new AiCredentials('', ''));

        $message = json_decode($this->logged('stdin'), true);
        self::assertIsArray($message);
        self::assertSame(
            ['image', 'image', 'text'],
            array_column($message['message']['content'], 'type'),
        );
        self::assertStringContainsString('These 2 images', $message['message']['content'][2]['text']);
    }

    public function testCodexWithoutAnAnswerIsExplained(): void
    {
        $this->tool('codex', '');

        $this->expectException(UserError::class);
        $this->expectExceptionMessage('ИИ ответил не по формату');

        $this->codex()->read(ReceiptInput::text('milk 1200'), $this->context('ru'), new AiCredentials('', 'gpt-5.1'));
    }

    public function testInstructionsAskToTranslateIntoTheChosenLanguage(): void
    {
        $russian = ReceiptPrompt::instructions($this->context('ru'));
        $english = ReceiptPrompt::instructions($this->context('en'));

        self::assertStringContainsString('translate the name into Russian', $russian);
        self::assertStringContainsString('translate the name into English', $english);
    }

    /**
     * A stand-in tool: logs its arguments, stdin, environment and folder; Codex also gets the photo copied out and the
     * answer written where --output-last-message points, Claude Code prints the answer.
     */
    private function tool(string $name, string $answer, int $exit = 0): void
    {
        $log    = escapeshellarg($this->log);
        $answer = escapeshellarg($answer);
        $codex  = $name === 'codex' ? <<<SH
            out=""; prev=""; n=0
            for a in "\$@"; do
              [ "\$prev" = "--output-last-message" ] && out="\$a"
              case "\$a" in --image=*) n=\$((n + 1)); cp "\${a#--image=}" {$log}/image-\$n ;; esac
              prev="\$a"
            done
            [ -n {$answer} ] && printf '%s' {$answer} > "\$out"
            SH : "printf '%s\\n' {$answer}";

        $this->files->dumpFile($this->bin . '/' . $name, <<<SH
            #!/bin/sh
            printf '%s\\0' "\$@" > {$log}/args
            cat > {$log}/stdin
            env > {$log}/env
            pwd > {$log}/cwd
            {$codex}
            exit {$exit}
            SH);
        $this->files->chmod($this->bin . '/' . $name, 0755);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function claudeOutput(array $result): string
    {
        return json_encode(['type' => 'system', 'subtype' => 'init'], JSON_THROW_ON_ERROR) . "\n"
            . json_encode(['type' => 'result', 'subtype' => 'success', ...$result], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<mixed>
     */
    private function answer(): array
    {
        return (array) json_decode(self::ANSWER, true);
    }

    /**
     * @return list<string>
     */
    private function arguments(): array
    {
        return explode("\0", rtrim($this->logged('args'), "\0"));
    }

    private function logged(string $name): string
    {
        return $this->files->readFile($this->log . '/' . $name);
    }

    private function cli(): LocalCli
    {
        return new LocalCli($this->files, new NullLogger(), [$this->bin]);
    }

    private function claudeCode(): ClaudeCodeReceiptReader
    {
        return new ClaudeCodeReceiptReader($this->cli(), new NullLogger());
    }

    private function codex(): CodexReceiptReader
    {
        return new CodexReceiptReader($this->cli(), $this->files);
    }

    private function photo(): ReceiptInput
    {
        return $this->photos(1);
    }

    private function photos(int $count): ReceiptInput
    {
        $image = ReceiptImage::fromBytes((string) base64_decode(ReceiptReadersTest::PNG, true));

        return ReceiptInput::photos(array_fill(0, $count, $image));
    }

    private function context(string $language): ReadingContext
    {
        $dram = new Currency('AMD', 0);

        return new ReadingContext(new DateTimeImmutable('2026-11-10'), $dram, ['AMD' => $dram], $language);
    }
}
