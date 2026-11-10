<?php

declare(strict_types=1);

namespace App\Bot\Telegram;

final readonly class TelegramUser
{
    public function __construct(
        public int $id,
        public string $firstName,
    ) {
    }
}
