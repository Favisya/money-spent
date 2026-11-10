<?php

declare(strict_types=1);

namespace App\Bot\Reply;

use App\Bot\Telegram\TelegramApiException;
use App\Bot\Telegram\TelegramClient;

/**
 * A deferred bot action, executed after the database transaction commits.
 */
interface Reply
{
    /**
     * @throws TelegramApiException
     */
    public function send(TelegramClient $client): void;
}
