<?php

declare(strict_types=1);

namespace App\Bot\Callback;

use App\Application\Expense\ExpenseEditor;
use App\Application\Reference\Category;
use App\Bot\BotContext;
use App\Bot\Reply\CallbackAnswer;
use App\Bot\Reply\EditReply;
use App\Bot\Telegram\CallbackQuery;
use App\Bot\Telegram\InlineButton;
use App\Bot\Telegram\InlineKeyboard;
use App\Bot\View\ExpenseCard;
use App\Infrastructure\Persistence\CategoryRepository;

final readonly class ChangeCategoryCallback implements BotCallback
{
    private const string PREFIX = 'cat';
    private const int PER_ROW   = 2;

    public function __construct(
        private ExpenseEditor $editor,
        private CategoryRepository $categories,
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
        $buttons     = array_map(
            static fn (Category $category): InlineButton => new InlineButton(
                $category->title,
                SetCategoryCallback::data($expenseId, $category->id),
            ),
            $this->categories->findActive(),
        );

        $keyboard = InlineKeyboard::grid($buttons, self::PER_ROW)
            ->withRow(new InlineButton('« Назад', ShowCardCallback::data($expenseId)));

        return [
            new EditReply($context->chatId, $context->messageId, $this->card->text($expense), $keyboard),
            new CallbackAnswer($query->id),
        ];
    }
}
