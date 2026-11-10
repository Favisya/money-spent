<?php

declare(strict_types=1);

namespace App\Bot\Command;

use App\Bot\BotContext;
use App\Bot\Reply\Reply;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(self::TAG)]
interface BotCommand
{
    public const string TAG = 'app.bot_command';

    /**
     * Command name without the leading slash.
     */
    public function name(): string;

    public function description(): string;

    /**
     * @return list<Reply>
     */
    public function handle(BotContext $context, string $arguments): array;
}
