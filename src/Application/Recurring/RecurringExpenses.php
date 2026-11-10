<?php

declare(strict_types=1);

namespace App\Application\Recurring;

use App\Application\Clock\LocalCalendar;
use App\Application\Expense\ExpenseEditor;
use App\Application\Expense\ExpenseRecorder;
use App\Application\Parsing\ParserFactory;
use App\Application\Participant\Participant;
use App\Application\UserError;
use App\Domain\Parsing\ParsedExpense;
use App\Domain\Recurring\MonthlyRecurrence;
use App\Domain\Split\SplitType;
use App\Infrastructure\Persistence\CategoryRepository;
use App\Infrastructure\Persistence\ParticipantRepository;
use App\Infrastructure\Persistence\RecurringExpenseRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Monthly expenses such as subscriptions: each one is booked on its day of the month, missed months are caught up
 * (the desktop app may stay closed for a while), and every occurrence has a fixed uuid so it is never booked twice.
 */
final readonly class RecurringExpenses
{
    private const int CATCH_UP_MONTHS = 24;
    private const string NAMESPACE    = '6f1d2c7e-3b9a-4e15-9c0d-8a7b2e4f1c33';

    public function __construct(
        private RecurringExpenseRepository $recurring,
        private ExpenseRecorder $recorder,
        private ExpenseEditor $editor,
        private ParticipantRepository $participants,
        private CategoryRepository $categories,
        private ParserFactory $parsers,
        private LocalCalendar $calendar,
        private Connection $connection,
        private string $defaultCategoryCode,
    ) {
    }

    public function create(int $payerId, string $text, SplitType $whose, int $dayOfMonth): RecurringExpense
    {
        $payer  = $this->payer($payerId);
        $today  = $this->calendar->today();
        $parsed = $this->parsers->create()->parse($text, $today);
        $rule   = $this->recurrence($dayOfMonth);
        $start  = $this->firstMonth($rule, $today);

        return $this->store(new RecurringDraft(
            $payer->id,
            $parsed->amount,
            $this->categoryId($parsed->categoryCodes),
            $whose === SplitType::Equal ? $parsed->split : $whose,
            $parsed->note,
            $dayOfMonth,
            $start,
        ));
    }

    /**
     * Repeats an existing expense every month on the same day, starting with the month after it.
     */
    public function fromExpense(int $expenseId): RecurringExpense
    {
        $expense = $this->editor->findActive($expenseId);

        if ($expense->recurringId !== null) {
            throw new UserError('Эта трата уже повторяется каждый месяц.');
        }

        return $this->connection->transactional(function () use ($expense): RecurringExpense {
            $recurring = $this->store(new RecurringDraft(
                $expense->payerId,
                $expense->amount,
                $expense->categoryId,
                $expense->split,
                $expense->note,
                (int) $expense->spentAt->format('j'),
                $expense->spentAt->modify('first day of next month'),
            ));
            $this->recurring->linkExpense($expense->id, $recurring->id);

            return $recurring;
        });
    }

    public function setActive(int $id, bool $active): RecurringExpense
    {
        $recurring = $this->existing($id);

        if ($active && !$recurring->active) {
            $today = $this->calendar->today();
            $first = $this->firstMonth($this->recurrence($recurring->dayOfMonth), $today);

            if ($first->format('Y-m') > $recurring->nextMonth->format('Y-m')) {
                $this->recurring->advance($id, $first);
            }
        }

        $this->recurring->setActive($id, $active);

        return $this->existing($id);
    }

    public function delete(int $id): void
    {
        $this->existing($id);
        $this->recurring->delete($id);
    }

    /**
     * Books every due occurrence of every active recurring expense.
     *
     * @return int number of expenses booked
     */
    public function run(): int
    {
        $today  = $this->calendar->today();
        $booked = 0;

        foreach ($this->recurring->findActive() as $recurring) {
            $dates = $this->recurrence($recurring->dayOfMonth)
                ->dueDates($recurring->nextMonth, $today, self::CATCH_UP_MONTHS);

            if ($dates === []) {
                continue;
            }

            $payer = $this->payer($recurring->payerId);

            $this->connection->transactional(function () use ($recurring, $payer, $dates, &$booked): void {
                foreach ($dates as $date) {
                    $this->recorder->record(
                        $payer,
                        $this->occurrence($recurring, $date),
                        $this->uuid($recurring, $date),
                        $recurring->id,
                    );
                    ++$booked;
                }

                $this->recurring->advance($recurring->id, $dates[count($dates) - 1]->modify('first day of next month'));
            });
        }

        return $booked;
    }

    private function occurrence(RecurringExpense $recurring, DateTimeImmutable $date): ParsedExpense
    {
        return new ParsedExpense(
            $recurring->amount,
            $date,
            [$recurring->categoryCode],
            $recurring->split,
            $recurring->note,
            true,
        );
    }

    private function uuid(RecurringExpense $recurring, DateTimeImmutable $date): string
    {
        return Uuid::v5(
            Uuid::fromString(self::NAMESPACE),
            sprintf('recurring:%d:%s', $recurring->id, $date->format('Y-m')),
        )->toRfc4122();
    }

    private function store(RecurringDraft $draft): RecurringExpense
    {
        return $this->existing($this->recurring->insert($draft));
    }

    private function existing(int $id): RecurringExpense
    {
        return $this->recurring->find($id) ?? throw new UserError('Регулярная трата не найдена.');
    }

    private function payer(int $id): Participant
    {
        return $this->participants->find($id) ?? throw new UserError('Выбери, кто платит.');
    }

    /**
     * This month while its day is still ahead (or today), otherwise next month.
     */
    private function firstMonth(MonthlyRecurrence $rule, DateTimeImmutable $today): DateTimeImmutable
    {
        $month = $today->setTime(0, 0)->modify('first day of this month');

        return $rule->dateIn($month) >= $today->setTime(0, 0) ? $month : $month->modify('first day of next month');
    }

    private function recurrence(int $dayOfMonth): MonthlyRecurrence
    {
        if ($dayOfMonth < 1 || $dayOfMonth > 31) {
            throw new UserError('День месяца — от 1 до 31.');
        }

        return new MonthlyRecurrence($dayOfMonth);
    }

    /**
     * @param list<string> $codes
     */
    private function categoryId(array $codes): int
    {
        $found = $this->categories->findByCodes(count($codes) === 1 ? $codes : [$this->defaultCategoryCode]);

        $category = $found[0] ?? throw new UserError('Не нашёл категорию.');

        return $category->id;
    }
}
