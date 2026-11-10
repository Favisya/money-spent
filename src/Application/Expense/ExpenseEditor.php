<?php

declare(strict_types=1);

namespace App\Application\Expense;

use App\Application\Participant\Participant;
use App\Application\Rates\RateResolver;
use App\Application\Reference\CurrencyCatalog;
use App\Application\UserError;
use App\Domain\Money\Money;
use App\Domain\Split\SplitType;
use App\Infrastructure\Persistence\CategoryRepository;
use App\Infrastructure\Persistence\ExpenseRepository;
use App\Infrastructure\Persistence\LedgerEntryRepository;
use App\Infrastructure\Persistence\LedgerTable;
use App\Infrastructure\Persistence\ParticipantRepository;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ExpenseEditor
{
    public function __construct(
        private ExpenseRepository $expenses,
        private CategoryRepository $categories,
        private LedgerEntryRepository $entries,
        private ParticipantRepository $participants,
        private CurrencyCatalog $currencies,
        private RateResolver $rates,
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

        if (mb_strlen(trim($note)) > NoteText::MAX_LENGTH) {
            throw new UserError('Заметка — до %max% символов.', ['%max%' => NoteText::MAX_LENGTH]);
        }

        $this->expenses->updateNote($expenseId, NoteText::clean($note));

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

    /**
     * Moves the expense to another day; the rate is taken anew for that day.
     */
    public function changeDate(int $expenseId, string $day): ExpenseView
    {
        $expense = $this->findActive($expenseId);

        return $this->reprice($expense, ExpenseInput::day($day, $expense->spentAt), $expense->amount);
    }

    /**
     * New amount in any known currency; the amount in the base currency is recalculated.
     */
    public function changeAmount(int $expenseId, string $amount, string $currencyCode): ExpenseView
    {
        $expense = $this->findActive($expenseId);

        try {
            $currency = $this->currencies->get($currencyCode);
        } catch (InvalidArgumentException) {
            throw new UserError('Такой валюты нет.');
        }

        return $this->reprice($expense, $expense->spentAt, ExpenseInput::amount($amount, $currency));
    }

    /**
     * Somebody else paid: the expense stays shared or stays with the same person.
     */
    public function changePayer(int $expenseId, int $payerId): ExpenseView
    {
        $expense = $this->findActive($expenseId);

        if ($this->participants->find($payerId) === null) {
            throw new UserError('Такого участника нет.');
        }

        $everyone = array_map(static fn (Participant $p): int => $p->id, $this->participants->all());
        $owner    = $expense->split->ownerId($expense->payerId, $everyone);

        $this->expenses->updatePayer($expenseId, $payerId, SplitType::forOwner($owner, $payerId));

        return $this->findActive($expenseId);
    }

    public function delete(int $expenseId): ExpenseView
    {
        $expense = $this->findActive($expenseId);
        $this->entries->softDelete(LedgerTable::Expense, $expenseId);

        return $expense;
    }

    private function reprice(ExpenseView $expense, DateTimeImmutable $spentAt, Money $amount): ExpenseView
    {
        $rate = $this->rates->find($amount->currency, $spentAt);
        $this->expenses->updateMoney($expense->id, $spentAt, $amount, $rate);

        return $this->findActive($expense->id);
    }
}
