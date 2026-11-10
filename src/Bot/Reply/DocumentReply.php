<?php

declare(strict_types=1);

namespace App\Bot\Reply;

use App\Bot\Telegram\TelegramClient;

final readonly class DocumentReply implements Reply
{
    public function __construct(
        public int $chatId,
        public string $filename,
        public string $content,
        public string $caption,
    ) {
    }

    public function send(TelegramClient $client): void
    {
        $client->sendDocument($this->chatId, $this->filename, $this->content, $this->caption);
    }
}
