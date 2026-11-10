<?php

declare(strict_types=1);

namespace App\Bot;

use App\Application\Participant\Participant;
use App\Application\UserError;
use App\Bot\Callback\CallbackRouter;
use App\Bot\Command\CommandRouter;
use App\Bot\Message\ExpenseMessageHandler;
use App\Bot\Reply\CallbackAnswer;
use App\Bot\Reply\Reply;
use App\Bot\Reply\TextReply;
use App\Bot\Telegram\Update;
use App\Bot\View\BotTranslator;
use App\Bot\View\Html;
use DateTimeImmutable;

final readonly class BotRouter
{
    public function __construct(
        private CommandRouter $commands,
        private CallbackRouter $callbacks,
        private ExpenseMessageHandler $expenses,
        private BotTranslator $translator,
    ) {
    }

    /**
     * @return list<Reply>
     */
    public function route(Update $update, Participant $participant, DateTimeImmutable $today): array
    {
        if ($update->message !== null) {
            $message = $update->message;
            $context = new BotContext($participant, $message->chatId, $message->messageId, $today);

            try {
                return $message->isCommand()
                    ? $this->commands->handle($context, $message->text)
                    : $this->expenses->handle($context, $message->text);
            } catch (UserError $error) {
                return [new TextReply($context->chatId, '⚠️ ' . Html::escape($this->translator->error($error)))];
            }
        }

        if ($update->callback !== null) {
            $callback = $update->callback;
            $context  = new BotContext($participant, $callback->chatId, $callback->messageId, $today);

            try {
                return $this->callbacks->handle($context, $callback);
            } catch (UserError $error) {
                return [new CallbackAnswer($callback->id, $this->translator->error($error))];
            }
        }

        return [];
    }
}
