<?php

declare(strict_types=1);

namespace App\Bot\Command;

use App\Bot\BotContext;
use App\Bot\Reply\TextReply;
use App\Bot\View\BotTranslator;
use App\Bot\View\Html;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class StartCommand implements BotCommand
{
    /** Example line => what it means (empty when the line speaks for itself). */
    private const array EXAMPLES = [
        '17000 продукты'           => '',
        '18,5 eur чатгпт 10.11'    => '',
        '9700 доставка кари вчера' => '',
        '700 кофе лично'           => 'только моя трата',
        '3000 цветы ей'            => 'целиком за другого',
        '-2500 руб возврат'        => 'возврат денег',
    ];

    /**
     * @param iterable<BotCommand> $commands
     */
    public function __construct(
        #[AutowireIterator(BotCommand::TAG)]
        private iterable $commands,
        private BotTranslator $translator,
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
            $this->translator->text(
                'Привет, %name%! Ты в списке участников.',
                ['%name%' => Html::escape($context->participant->name)],
            ),
            '',
            $this->translator->text('Трата — одним сообщением: сумма, валюта, категория, дата, заметка.'),
        ];

        foreach (self::EXAMPLES as $example => $meaning) {
            $line    = '<code>' . Html::escape($this->translator->text($example)) . '</code>';
            $lines[] = $meaning === '' ? $line : $line . ' — ' . Html::escape($this->translator->text($meaning));
        }

        $lines[] = '';
        $lines[] = $this->translator->text('Команды:');

        foreach ($this->commands as $command) {
            $lines[] = sprintf(
                '/%s — %s',
                $command->name(),
                Html::escape($this->translator->text($command->description())),
            );
        }

        return [new TextReply($context->chatId, implode("\n", $lines))];
    }
}
