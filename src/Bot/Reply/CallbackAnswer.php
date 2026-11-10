<?php

declare(strict_types=1);

namespace App\Bot\Reply;

use App\Bot\Telegram\TelegramClient;

final readonly class CallbackAnswer implements Reply
{
    public function __construct(
        public string $callbackId,
        public ?string $text = null,
    ) {
    }

    public function send(TelegramClient $client): void
    {
        $client->answerCallback($this->callbackId, $this->text);
    }
}
