<?php

declare(strict_types=1);

namespace App\Bot\Callback;

use App\Bot\BotContext;
use App\Bot\Reply\CallbackAnswer;
use App\Bot\Reply\Reply;
use App\Bot\Telegram\CallbackQuery;
use App\Bot\View\BotTranslator;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class CallbackRouter
{
    /**
     * @param iterable<BotCallback> $callbacks
     */
    public function __construct(
        #[AutowireIterator(BotCallback::TAG)]
        private iterable $callbacks,
        private BotTranslator $translator,
    ) {
    }

    /**
     * @return list<Reply>
     */
    public function handle(BotContext $context, CallbackQuery $query): array
    {
        [$prefix, $payload] = array_pad(explode(':', $query->data, 2), 2, '');

        foreach ($this->callbacks as $callback) {
            if ($callback->prefix() === $prefix) {
                return $callback->handle($context, $query, $payload);
            }
        }

        return [new CallbackAnswer($query->id, $this->translator->text('Кнопка устарела.'))];
    }
}
