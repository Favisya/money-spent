<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Application\UserError;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs an AI command-line tool installed on this Mac (Claude Code, Codex) without a shell, in a throwaway folder,
 * and with only the environment it needs: the app's own secrets never reach it.
 */
final readonly class LocalCli
{
    public const int TIMEOUT = 150;

    private const int LOG_LIMIT      = 2000;
    private const string SYSTEM_PATH = '/usr/bin:/bin:/usr/sbin:/sbin';
    private const array SYSTEM_DIRS  = ['/opt/homebrew/bin', '/usr/local/bin'];
    private const array HOME_DIRS    = ['.local/bin', '.claude/local', '.npm-global/bin', '.volta/bin', '.bun/bin'];
    private const array ACCOUNT_ENV  = ['USER', 'LOGNAME'];
    private const array PASSED_ENV   = [
        'HOME', 'USER', 'LOGNAME', 'TMPDIR', 'LANG', 'LC_ALL', 'LC_CTYPE', '__CF_USER_TEXT_ENCODING',
        'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'NO_PROXY', 'http_proxy', 'https_proxy', 'all_proxy', 'no_proxy',
        'CLAUDE_CONFIG_DIR', 'CODEX_HOME',
    ];

    /**
     * @param list<string> $extraDirs where to look for tools besides PATH and the usual install folders
     */
    public function __construct(
        private Filesystem $files,
        private LoggerInterface $logger,
        private array $extraDirs = [],
    ) {
    }

    /**
     * @template T
     *
     * @param callable(string): T $job gets an empty private folder that is removed afterwards
     *
     * @return T
     */
    public function inScratchDir(callable $job): mixed
    {
        $dir = sys_get_temp_dir() . '/receipt-' . bin2hex(random_bytes(8));
        $this->files->mkdir($dir, 0700);

        try {
            return $job($dir);
        } finally {
            $this->files->remove($dir);
        }
    }

    /**
     * @param list<string> $arguments
     *
     * @return string what the tool printed
     */
    public function run(string $tool, array $arguments, string $workDir, string $input): string
    {
        $executable = $this->locate($tool);
        $process    = new Process(
            [$executable, ...$arguments],
            $workDir,
            $this->environment($executable),
            $input,
            self::TIMEOUT,
        );

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new UserError('%tool% не ответил за %seconds% с — попробуй ещё раз.', [
                '%tool%'    => $tool,
                '%seconds%' => self::TIMEOUT,
            ]);
        }

        if (!$process->isSuccessful()) {
            $this->logger->error('AI command-line tool failed', [
                'tool'  => $tool,
                'exit'  => $process->getExitCode(),
                'error' => mb_substr(
                    trim($process->getErrorOutput() . "\n" . $process->getOutput()),
                    -self::LOG_LIMIT,
                ),
            ]);

            throw new UserError(
                '%tool% завершился с ошибкой. Запусти его в Терминале: '
                . 'возможно, нужно войти или кончился лимит подписки.',
                ['%tool%' => $tool],
            );
        }

        return $process->getOutput();
    }

    private function locate(string $tool): string
    {
        $home = $this->home();
        $dirs = [
            ...$this->extraDirs,
            ...self::SYSTEM_DIRS,
            ...($home === '' ? [] : array_map(static fn (string $dir): string => $home . '/' . $dir, self::HOME_DIRS)),
        ];

        return new ExecutableFinder()->find($tool, null, $dirs)
            ?? throw new UserError(
                '%tool% не найден на этом компьютере — '
                . 'установи его и войди в него или выбери другой способ в настройках ИИ.',
                ['%tool%' => $tool],
            );
    }

    /**
     * Every inherited variable is dropped except the few a signed-in tool needs.
     *
     * @return array<string, string|false>
     */
    private function environment(string $executable): array
    {
        $environment = array_fill_keys(array_map('strval', array_keys([...getenv(), ...$_ENV])), false);

        foreach (self::PASSED_ENV as $name) {
            $value = getenv($name);

            if (is_string($value) && $value !== '') {
                $environment[$name] = $value;
            }
        }

        $user = $this->user();

        foreach (self::ACCOUNT_ENV as $name) {
            if (!is_string($environment[$name] ?? null) && $user !== '') {
                $environment[$name] = $user;
            }
        }

        $environment['PATH'] = dirname($executable) . ':' . self::SYSTEM_PATH;

        return $environment;
    }

    /**
     * The account name: the tools find their sign-in in the macOS keychain by it, and the Mac app starts PHP without
     * USER in its environment.
     */
    private function user(): string
    {
        $user = getenv('USER');

        if (is_string($user) && $user !== '') {
            return $user;
        }

        if (!function_exists('posix_getpwuid')) {
            return '';
        }

        $account = posix_getpwuid(posix_geteuid());

        return is_array($account) ? $account['name'] : '';
    }

    private function home(): string
    {
        $home = getenv('HOME');

        return is_string($home) ? $home : '';
    }
}
