<?php

declare(strict_types=1);

namespace App\Application\Participant;

/**
 * $locale is the bot language the participant picked with /language; null means their Telegram language.
 */
final readonly class Participant
{
    public function __construct(
        public int $id,
        public ?int $telegramId,
        public string $name,
        public ?ParticipantColor $color = null,
        public ?string $locale = null,
    ) {
    }
}
