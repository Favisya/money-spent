<?php

declare(strict_types=1);

namespace App\Application\Expense;

use App\Application\Participant\Participant;
use App\Application\UserError;
use App\Domain\Split\SplitType;
use App\Infrastructure\Persistence\CategoryRepository;
use App\Infrastructure\Persistence\ExpenseRepository;
use App\Infrastructure\Persistence\LedgerEntryRepository;
use App\Infrastructure\Persistence\LedgerTable;

final readonly class ExpenseEditor
{
    private const int NOTE_LENGTH = 255;

    public function __construct(
        private ExpenseRepository $expenses,
        private CategoryRepository $categories,
        private LedgerEntryRepository $entries,
    ) {
    }

    public function findActive(int $expenseId): ExpenseView
    {
        $expense = $this->expenses->findView($expenseId);

        if ($expense === null || $expense->deleted) {
            throw new UserError('Трата не найдена или уже удалена.');
        }

        return $expense;
    }

    /**
     * For the shared bot chat: only the author may edit an expense. The local web app is trusted as a whole.
     */
    public function ownedActive(Participant $actor, int $expenseId): ExpenseView
    {
        $expense = $this->findActive($expenseId);

        if ($expense->payerId !== $actor->id) {
            throw new UserError('Менять можно только свои траты.');
        }

        return $expense;
    }

    public function changeNote(int $expenseId, string $note): ExpenseView
    {
        $this->findActive($expenseId);
        $note = trim(preg_replace('/\s+/u', ' ', $note) ?? '');

        if (mb_strlen($note) > self::NOTE_LENGTH) {
            throw new UserError(sprintf('Заметка — до %d символов.', self::NOTE_LENGTH));
        }

        $this->expenses->updateNote($expenseId, $note === '' ? null : $note);

        return $this->findActive($expenseId);
    }

    public function changeCategory(int $expenseId, int $categoryId): ExpenseView
    {
        $this->findActive($expenseId);

        if ($this->categories->findActiveById($categoryId) === null) {
            throw new UserError('Такой категории нет.');
        }

        $this->expenses->updateCategory($expenseId, $categoryId);

        return $this->findActive($expenseId);
    }

    public function changeSplit(int $expenseId, SplitType $split): ExpenseView
    {
        $this->findActive($expenseId);
        $this->expenses->updateSplit($expenseId, $split);

        return $this->findActive($expenseId);
    }

    public function delete(int $expenseId): ExpenseView
    {
        $expense = $this->findActive($expenseId);
        $this->entries->softDelete(LedgerTable::Expense, $expenseId);

        return $expense;
    }
}
