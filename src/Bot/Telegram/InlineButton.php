<?php

declare(strict_types=1);

namespace App\Bot\Telegram;

use InvalidArgumentException;

final readonly class InlineButton
{
    private const int MAX_CALLBACK_BYTES = 64;

    public function __construct(
        public string $text,
        public string $callbackData,
    ) {
        if ($callbackData === '' || strlen($callbackData) > self::MAX_CALLBACK_BYTES) {
            throw new InvalidArgumentException('Callback data must be 1-64 bytes.');
        }
    }
}
