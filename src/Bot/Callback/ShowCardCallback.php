<?php

declare(strict_types=1);

namespace App\Bot\Callback;

use App\Application\Expense\ExpenseEditor;
use App\Bot\BotContext;
use App\Bot\Reply\CallbackAnswer;
use App\Bot\Reply\EditReply;
use App\Bot\Telegram\CallbackQuery;
use App\Bot\View\ExpenseCard;

final readonly class ShowCardCallback implements BotCallback
{
    private const string PREFIX = 'card';

    public function __construct(
        private ExpenseEditor $editor,
        private ExpenseCard $card,
    ) {
    }

    public static function data(int $expenseId): string
    {
        return sprintf('%s:%d', self::PREFIX, $expenseId);
    }

    public function prefix(): string
    {
        return self::PREFIX;
    }

    public function handle(BotContext $context, CallbackQuery $query, string $payload): array
    {
        [$expenseId] = CallbackPayload::ids($payload, 1);
        $expense     = $this->editor->ownedActive($context->participant, $expenseId);

        return [
            new EditReply(
                $context->chatId,
                $context->messageId,
                $this->card->text($expense),
                $this->card->keyboard($expense),
            ),
            new CallbackAnswer($query->id),
        ];
    }
}
