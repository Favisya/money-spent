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

    /**
     * Replaces the command menu for users with the given Telegram language, or for everyone else when it is null.
     *
     * @param array<string, string> $commands command name without the slash => description
     *
     * @throws TelegramApiException
     */
    public function setMyCommands(array $commands, ?string $languageCode = null): void;
}
