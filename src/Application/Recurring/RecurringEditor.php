<?php

declare(strict_types=1);

namespace App\Application\Recurring;

use App\Application\Clock\LocalCalendar;
use App\Application\Expense\ExpenseInput;
use App\Application\Expense\JointExpenseRecorder;
use App\Application\Expense\NoteText;
use App\Application\Participant\Participant;
use App\Application\Reference\CurrencyCatalog;
use App\Application\UserError;
use App\Domain\Money\Money;
use App\Domain\Split\SplitType;
use App\Infrastructure\Persistence\CategoryRepository;
use App\Infrastructure\Persistence\ParticipantRepository;
use App\Infrastructure\Persistence\RecurringExpenseRepository;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Saves a recurring expense row edited in place. Already booked expenses stay as they were.
 */
final readonly class RecurringEditor
{
    public function __construct(
        private RecurringExpenseRepository $recurring,
        private RecurringExpenses $expenses,
        private ParticipantRepository $participants,
        private CategoryRepository $categories,
        private CurrencyCatalog $currencies,
        private LocalCalendar $calendar,
    ) {
    }

    public function update(int $id, RecurringChange $change): RecurringExpense
    {
        $current = $this->recurring->find($id) ?? throw new UserError('Регулярная трата не найдена.');
        $payerId = $this->payerId($change->payer);

        $this->recurring->update($id, new RecurringDraft(
            $payerId,
            $this->amount($change),
            $this->categoryId($current, $change->categoryId),
            $this->split($current, $payerId, $change->split),
            $this->note($change->note),
            $change->dayOfMonth,
            $this->nextMonth($current, $change->dayOfMonth),
        ));

        return $this->recurring->find($id) ?? throw new UserError('Регулярная трата не найдена.');
    }

    /**
     * @return int|null null: both pay, half each
     */
    private function payerId(string $payer): ?int
    {
        $people = $this->participants->all();

        if ($payer === JointExpenseRecorder::BOTH) {
            if (count($people) < 2) {
                throw new UserError('«Оба» можно выбрать, когда зарегистрированы оба участника.');
            }

            return null;
        }

        foreach ($people as $participant) {
            if ((string) $participant->id === $payer) {
                return $participant->id;
            }
        }

        throw new UserError('Выбери, кто платит.');
    }

    private function amount(RecurringChange $change): Money
    {
        try {
            $currency = $this->currencies->get($change->currency);
        } catch (InvalidArgumentException) {
            throw new UserError('Такой валюты нет.');
        }

        return ExpenseInput::amount($change->amount, $currency);
    }

    /**
     * A hidden category may stay on the row, it just can't be chosen anew.
     */
    private function categoryId(RecurringExpense $current, int $categoryId): int
    {
        if ($categoryId !== $current->categoryId && $this->categories->findActiveById($categoryId) === null) {
            throw new UserError('Такой категории нет.');
        }

        return $categoryId;
    }

    /**
     * A new payer keeps the expense with the same owner; "both" is always shared.
     */
    private function split(RecurringExpense $current, ?int $payerId, string $split): SplitType
    {
        if ($payerId === null) {
            return SplitType::Equal;
        }

        if ($payerId !== $current->payerId) {
            $everyone = array_map(static fn (Participant $p): int => $p->id, $this->participants->all());
            $owner    = $current->payerId === null ? null : $current->split->ownerId($current->payerId, $everyone);

            return SplitType::forOwner($owner, $payerId);
        }

        return SplitType::tryFrom($split) ?? throw new UserError('Не понял, чья это трата.');
    }

    private function note(string $note): ?string
    {
        if (mb_strlen(trim($note)) > NoteText::MAX_LENGTH) {
            throw new UserError('Заметка — до %max% символов.', ['%max%' => NoteText::MAX_LENGTH]);
        }

        return NoteText::clean($note);
    }

    /**
     * A new day applies from the next occurrence that is still ahead, so changing it never books a past day at once.
     */
    private function nextMonth(RecurringExpense $current, int $dayOfMonth): DateTimeImmutable
    {
        $first = $this->expenses->recurrence($dayOfMonth)->firstMonth($this->calendar->today());

        return $first->format('Y-m') > $current->nextMonth->format('Y-m') ? $first : $current->nextMonth;
    }
}
