<?php

declare(strict_types=1);

namespace App\Bot\Message;

use App\Application\Expense\ExpenseRecorder;
use App\Application\Parsing\ParserFactory;
use App\Bot\BotContext;
use App\Bot\Reply\Reply;
use App\Bot\Reply\TextReply;
use App\Bot\View\ExpenseCard;
use App\Bot\View\ParseErrorReply;
use App\Domain\Parsing\ExpenseParseException;

final readonly class ExpenseMessageHandler
{
    public function __construct(
        private ParserFactory $parsers,
        private ExpenseRecorder $recorder,
        private ExpenseCard $card,
        private ParseErrorReply $errors,
    ) {
    }

    /**
     * @return list<Reply>
     */
    public function handle(BotContext $context, string $text): array
    {
        try {
            $parsed = $this->parsers->create()->parse($text, $context->today);
        } catch (ExpenseParseException $exception) {
            return [new TextReply($context->chatId, $this->errors->render($exception))];
        }

        $recorded = $this->recorder->record($context->participant, $parsed, MessageUuid::of($context));

        return [new TextReply(
            $context->chatId,
            $this->card->text($recorded->expense),
            $this->card->keyboard($recorded->expense, $recorded->categoryCandidates),
        )];
    }
}
