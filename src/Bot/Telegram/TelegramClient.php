<?php

declare(strict_types=1);

namespace App\Bot\Telegram;

/**
 * Outgoing bot replies. Texts are Telegram HTML: callers escape user content.
 */
interface TelegramClient
{
    /**
     * @throws TelegramApiException
     */
    public function sendMessage(int $chatId, string $html, ?InlineKeyboard $keyboard = null): void;

    /**
     * @throws TelegramApiException
     */
    public function editMessage(int $chatId, int $messageId, string $html, ?InlineKeyboard $keyboard = null): void;

    /**
     * @throws TelegramApiException
     */
    public function answerCallback(string $callbackId, ?string $text = null): void;

    /**
     * @throws TelegramApiException
     */
    public function sendDocument(int $chatId, string $filename, string $content, string $caption): void;
}
