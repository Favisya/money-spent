<?php

declare(strict_types=1);

namespace App\Bot\Telegram;

/**
 * $languageCode is the IETF tag of the user's Telegram interface ("en", "pt-br"), unvalidated.
 */
final readonly class TelegramUser
{
    public function __construct(
        public int $id,
        public string $firstName,
        public ?string $languageCode = null,
    ) {
    }
}
