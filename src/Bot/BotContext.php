<?php

declare(strict_types=1);

namespace App\Bot;

use App\Application\Participant\Participant;
use DateTimeImmutable;

final readonly class BotContext
{
    public function __construct(
        public Participant $participant,
        public int $chatId,
        public int $messageId,
        public DateTimeImmutable $today,
    ) {
    }

    /**
     * Stable idempotency key of the incoming message.
     */
    public function messageKey(): string
    {
        return sprintf('tg:%d:%d', $this->chatId, $this->messageId);
    }
}
