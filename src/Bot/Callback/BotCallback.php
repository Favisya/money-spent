<?php

declare(strict_types=1);

namespace App\Bot\Callback;

use App\Bot\BotContext;
use App\Bot\Reply\Reply;
use App\Bot\Telegram\CallbackQuery;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(self::TAG)]
interface BotCallback
{
    public const string TAG = 'app.bot_callback';

    /**
     * Callback data looks like "<prefix>:<payload>".
     */
    public function prefix(): string;

    /**
     * @return list<Reply>
     */
    public function handle(BotContext $context, CallbackQuery $query, string $payload): array;
}
