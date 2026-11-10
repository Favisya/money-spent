<?php

declare(strict_types=1);

namespace App\Bot\Callback;

use App\Application\Expense\ExpenseEditor;
use App\Bot\BotContext;
use App\Bot\Reply\CallbackAnswer;
use App\Bot\Reply\EditReply;
use App\Bot\Telegram\CallbackQuery;
use App\Bot\View\ExpenseCard;

final readonly class DeleteExpenseCallback implements BotCallback
{
    private const string PREFIX = 'del';

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
        $this->editor->ownedActive($context->participant, $expenseId);
        $expense = $this->editor->delete($expenseId);

        return [
            new EditReply($context->chatId, $context->messageId, $this->card->deleted($expense)),
            new CallbackAnswer($query->id, 'Удалено'),
        ];
    }
}
