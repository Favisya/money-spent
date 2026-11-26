<?php

declare(strict_types=1);

namespace App\Application\Purchase;

/**
 * Who reads receipts: a model over its API with a key, or a command-line tool on this Mac signed in to a subscription.
 */
enum AiProvider: string
{
    case Claude     = 'claude';
    case OpenAi     = 'openai';
    case ClaudeCode = 'claude-code';
    case Codex      = 'codex';

    public function label(): string
    {
        return match ($this) {
            self::Claude     => 'Claude (Anthropic API)',
            self::OpenAi     => 'GPT (OpenAI API)',
            self::ClaudeCode => 'Claude Code',
            self::Codex      => 'Codex',
        };
    }

    public function needsKey(): bool
    {
        return $this->tool() === null;
    }

    /**
     * The command-line tool that does the reading, null for API providers.
     */
    public function tool(): ?string
    {
        return match ($this) {
            self::Claude, self::OpenAi => null,
            self::ClaudeCode           => 'claude',
            self::Codex                => 'codex',
        };
    }

    /**
     * An empty model lets the command-line tool use its own default.
     */
    public function defaultModel(): string
    {
        return match ($this) {
            self::Claude                  => 'claude-opus-5-5',
            self::OpenAi                  => 'gpt-5.1',
            self::ClaudeCode, self::Codex => '',
        };
    }

    public function keyConsole(): ?string
    {
        return match ($this) {
            self::Claude                  => 'https://console.anthropic.com/settings/keys',
            self::OpenAi                  => 'https://platform.openai.com/api-keys',
            self::ClaudeCode, self::Codex => null,
        };
    }
}
