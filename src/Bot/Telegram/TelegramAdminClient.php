<?php

declare(strict_types=1);

namespace App\Bot\Telegram;

interface TelegramAdminClient
{
    /**
     * @return list<array<mixed>>
     *
     * @throws TelegramApiException
     */
    public function getUpdates(int $offset, int $timeoutSeconds): array;

    /**
     * @throws TelegramApiException
     */
    public function setWebhook(string $url, string $secret): void;

    /**
     * @throws TelegramApiException
     */
    public function deleteWebhook(): void;
}
