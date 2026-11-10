<?php

declare(strict_types=1);

namespace App\Bot\Command;

use App\Bot\BotContext;
use App\Bot\Reply\Reply;
use App\Bot\Reply\TextReply;
use App\Bot\View\BotTranslator;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class CommandRouter
{
    private const string PATTERN = '~^/([a-z_]+)(?:@\w+)?(?:\s+(.*))?$~isu';

    /**
     * @param iterable<BotCommand> $commands
     */
    public function __construct(
        #[AutowireIterator(BotCommand::TAG)]
        private iterable $commands,
        private BotTranslator $translator,
    ) {
    }

    /**
     * @return list<Reply>
     */
    public function handle(BotContext $context, string $text): array
    {
        if (preg_match(self::PATTERN, trim($text), $match) === 1) {
            $name = strtolower($match[1]);

            foreach ($this->commands as $command) {
                if ($command->name() === $name) {
                    return $command->handle($context, trim($match[2] ?? ''));
                }
            }
        }

        return [new TextReply(
            $context->chatId,
            $this->translator->text('Не знаю такую команду. Список команд — /start'),
        )];
    }
}
