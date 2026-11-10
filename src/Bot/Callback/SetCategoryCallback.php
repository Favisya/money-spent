<?php

declare(strict_types=1);

namespace App\Bot\Callback;

use App\Application\Expense\ExpenseEditor;
use App\Bot\BotContext;
use App\Bot\Reply\CallbackAnswer;
use App\Bot\Reply\EditReply;
use App\Bot\Telegram\CallbackQuery;
use App\Bot\View\BotTranslator;
use App\Bot\View\ExpenseCard;

final readonly class SetCategoryCallback implements BotCallback
{
    private const string PREFIX = 'setcat';

    public function __construct(
        private ExpenseEditor $editor,
        private ExpenseCard $card,
        private BotTranslator $translator,
    ) {
    }

    public static function data(int $expenseId, int $categoryId): string
    {
        return sprintf('%s:%d:%d', self::PREFIX, $expenseId, $categoryId);
    }

    public function prefix(): string
    {
        return self::PREFIX;
    }

    public function handle(BotContext $context, CallbackQuery $query, string $payload): array
    {
        [$expenseId, $categoryId] = CallbackPayload::ids($payload, 2);
        $this->editor->ownedActive($context->participant, $expenseId);
        $expense = $this->editor->changeCategory($expenseId, $categoryId);

        return [
            new EditReply(
                $context->chatId,
                $context->messageId,
                $this->card->text($expense),
                $this->card->keyboard($expense),
            ),
            new CallbackAnswer($query->id, $this->translator->text(
                'Категория: %category%',
                ['%category%' => $this->translator->text($expense->categoryTitle)],
            )),
        ];
    }
}
