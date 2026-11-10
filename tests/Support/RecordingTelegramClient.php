<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Bot\Telegram\InlineKeyboard;
use App\Bot\Telegram\TelegramAdminClient;
use App\Bot\Telegram\TelegramClient;

final class RecordingTelegramClient implements TelegramClient, TelegramAdminClient
{
    /** @var list<array{method: string, chatId: int|null, text: string, keyboard: InlineKeyboard|null}> */
    public array $calls = [];

    public function sendMessage(int $chatId, string $html, ?InlineKeyboard $keyboard = null): void
    {
        $this->calls[] = ['method' => 'sendMessage', 'chatId' => $chatId, 'text' => $html, 'keyboard' => $keyboard];
    }

    public function editMessage(int $chatId, int $messageId, string $html, ?InlineKeyboard $keyboard = null): void
    {
        $this->calls[] = ['method' => 'editMessage', 'chatId' => $chatId, 'text' => $html, 'keyboard' => $keyboard];
    }

    public function answerCallback(string $callbackId, ?string $text = null): void
    {
        $this->calls[] = ['method' => 'answerCallback', 'chatId' => null, 'text' => $text ?? '', 'keyboard' => null];
    }

    public function sendDocument(int $chatId, string $filename, string $content, string $caption): void
    {
        $this->calls[] = [
            'method'   => 'sendDocument',
            'chatId'   => $chatId,
            'text'     => $filename . "\n" . $content,
            'keyboard' => null,
        ];
    }

    public function getUpdates(int $offset, int $timeoutSeconds): array
    {
        return [];
    }

    public function setWebhook(string $url, string $secret): void
    {
        $this->calls[] = ['method' => 'setWebhook', 'chatId' => null, 'text' => $url, 'keyboard' => null];
    }

    public function deleteWebhook(): void
    {
        $this->calls[] = ['method' => 'deleteWebhook', 'chatId' => null, 'text' => '', 'keyboard' => null];
    }

    /**
     * Recorded text: the language code (or "*"), a new line, the JSON of the commands.
     */
    public function setMyCommands(array $commands, ?string $languageCode = null): void
    {
        $this->calls[] = [
            'method' => 'setMyCommands',
            'chatId' => null,
            'text'   => ($languageCode ?? '*') . "\n"
                . json_encode($commands, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'keyboard' => null,
        ];
    }

    /**
     * @return array{method: string, chatId: int|null, text: string, keyboard: InlineKeyboard|null}
     */
    public function last(): array
    {
        $last = $this->calls[array_key_last($this->calls) ?? -1] ?? null;

        return $last ?? throw new \LogicException('Nothing was sent.');
    }

    public function lastText(): string
    {
        return html_entity_decode(strip_tags($this->last()['text']), ENT_QUOTES | ENT_HTML5);
    }
}
