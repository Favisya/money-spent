<?php

declare(strict_types=1);

namespace App\Bot\Telegram;

final readonly class IncomingMessage
{
    public function __construct(
        public int $messageId,
        public int $chatId,
        public TelegramUser $from,
        public string $text,
    ) {
    }

    public function isCommand(): bool
    {
        return str_starts_with($this->text, '/');
    }
}
