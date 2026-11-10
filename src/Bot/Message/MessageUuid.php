<?php

declare(strict_types=1);

namespace App\Bot\Message;

use App\Bot\BotContext;
use Symfony\Component\Uid\Uuid;

final class MessageUuid
{
    private const string NAMESPACE = '6f1b6c84-4a63-4e43-9d0a-8d7c2f4b5e21';

    /**
     * Deterministic id of the record created from a Telegram message, so a replayed message cannot create a twin.
     */
    public static function of(BotContext $context): string
    {
        return Uuid::v5(Uuid::fromString(self::NAMESPACE), $context->messageKey())->toRfc4122();
    }
}
