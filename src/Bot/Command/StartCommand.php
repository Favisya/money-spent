<?php

declare(strict_types=1);

namespace App\Bot\Command;

use App\Bot\BotContext;
use App\Bot\Reply\TextReply;
use App\Bot\View\Html;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class StartCommand implements BotCommand
{
    /**
     * @param iterable<BotCommand> $commands
     */
    public function __construct(
        #[AutowireIterator(BotCommand::TAG)]
        private iterable $commands,
    ) {
    }

    public function name(): string
    {
        return 'start';
    }

    public function description(): string
    {
        return 'регистрация и эта справка';
    }

    public function handle(BotContext $context, string $arguments): array
    {
        $lines = [
            sprintf('Привет, %s! Ты в списке участников.', Html::escape($context->participant->name)),
            '',
            'Трата — одним сообщением: сумма, валюта, категория, дата, заметка.',
            '<code>17000 продукты</code>',
            '<code>18,5 eur чатгпт 10.11</code>',
            '<code>9700 доставка кари вчера</code>',
            '<code>700 кофе лично</code> — только моя трата',
            '<code>3000 цветы ей</code> — целиком за другого',
            '<code>-2500 руб возврат</code> — возврат денег',
            '',
            'Команды:',
        ];

        foreach ($this->commands as $command) {
            $lines[] = sprintf('/%s — %s', $command->name(), Html::escape($command->description()));
        }

        return [new TextReply($context->chatId, implode("\n", $lines))];
    }
}
