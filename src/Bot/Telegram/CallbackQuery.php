<?php

declare(strict_types=1);

namespace App\Bot\Telegram;

final readonly class CallbackQuery
{
    public function __construct(
        public string $id,
        public TelegramUser $from,
        public int $chatId,
        public int $messageId,
        public string $data,
    ) {
    }
}
