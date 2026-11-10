<?php

declare(strict_types=1);

namespace App\Bot\Reply;

use App\Bot\Telegram\InlineKeyboard;
use App\Bot\Telegram\TelegramClient;

final readonly class TextReply implements Reply
{
    public function __construct(
        public int $chatId,
        public string $html,
        public ?InlineKeyboard $keyboard = null,
    ) {
    }

    public function send(TelegramClient $client): void
    {
        $client->sendMessage($this->chatId, $this->html, $this->keyboard);
    }
}
