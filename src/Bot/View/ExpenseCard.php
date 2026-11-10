<?php

declare(strict_types=1);

namespace App\Bot\View;

use App\Application\Expense\ExpenseView;
use App\Application\Reference\Category;
use App\Bot\Callback\ChangeCategoryCallback;
use App\Bot\Callback\DeleteExpenseCallback;
use App\Bot\Callback\SetCategoryCallback;
use App\Bot\Telegram\InlineButton;
use App\Bot\Telegram\InlineKeyboard;
use App\Domain\Split\SplitType;

final readonly class ExpenseCard
{
    public function __construct(
        private MoneyFormatter $money,
    ) {
    }

    public function text(ExpenseView $expense): string
    {
        $lines = [
            sprintf(
                '✅ <b>%s</b> · %s',
                $this->money->format($expense->amount),
                Html::escape($expense->categoryTitle),
            ),
            sprintf(
                '📅 %s · платил(а) %s · %s',
                $expense->spentAt->format('d.m.Y'),
                Html::escape($expense->payerName),
                self::splitLabel($expense->split),
            ),
        ];

        if ($expense->note !== null) {
            $lines[] = '📝 ' . Html::escape($expense->note);
        }

        $lines[] = $this->conversionLine($expense);

        return implode("\n", array_filter($lines, static fn (string $line): bool => $line !== ''));
    }

    /**
     * @param list<Category> $candidates categories to choose from when the parser was not sure
     */
    public function keyboard(ExpenseView $expense, array $candidates = []): InlineKeyboard
    {
        $rows = [];

        if ($candidates !== []) {
            $rows[] = array_map(
                static fn (Category $category): InlineButton => new InlineButton(
                    '❓ ' . $category->title,
                    SetCategoryCallback::data($expense->id, $category->id),
                ),
                $candidates,
            );
        }

        $rows[] = [
            new InlineButton('✏️ Категория', ChangeCategoryCallback::data($expense->id)),
            new InlineButton('🗑 Удалить', DeleteExpenseCallback::data($expense->id)),
        ];

        return new InlineKeyboard($rows);
    }

    public function deleted(ExpenseView $expense): string
    {
        return '<s>' . $this->text($expense) . "</s>\n🗑 Удалено";
    }

    private function conversionLine(ExpenseView $expense): string
    {
        if ($expense->amountBase === null) {
            return sprintf(
                '⚠️ Курса %s на %s пока нет — пересчитаю, когда он появится.',
                $expense->amount->currency->code,
                $expense->spentAt->format('d.m.Y'),
            );
        }

        if ($expense->amountBase->currency->equals($expense->amount->currency) || $expense->rate === null) {
            return '';
        }

        return sprintf(
            '💱 × %s = %s',
            $this->money->rate($expense->rate),
            $this->money->format($expense->amountBase),
        );
    }

    private static function splitLabel(SplitType $split): string
    {
        return match ($split) {
            SplitType::Equal     => 'пополам',
            SplitType::PayerOnly => 'личное',
            SplitType::OtherOnly => 'за другого',
        };
    }
}
