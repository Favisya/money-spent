<?php

declare(strict_types=1);

namespace App\Application\Purchase;

use App\Application\Expense\ExpenseRecorder;
use App\Application\Expense\JointExpenseRecorder;
use App\Application\Expense\RecordedExpense;
use App\Application\Participant\Participant;
use App\Application\UserError;
use App\Domain\Parsing\ParsedExpense;
use App\Infrastructure\Persistence\ExpenseRepository;
use App\Infrastructure\Persistence\LedgerEntryRepository;
use App\Infrastructure\Persistence\LedgerTable;
use App\Infrastructure\Persistence\ParticipantRepository;
use App\Infrastructure\Persistence\ReceiptRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Keeps a reviewed receipt and its grocery expense in step: the expense always carries the receipt total, delivery
 * included.
 */
final readonly class ReceiptBook
{
    public function __construct(
        private ReceiptRepository $receipts,
        private ExpenseRepository $expenses,
        private LedgerEntryRepository $entries,
        private ParticipantRepository $participants,
        private ExpenseRecorder $recorder,
        private JointExpenseRecorder $jointRecorder,
        private Connection $connection,
        private string $purchaseCategoryCode,
    ) {
    }

    public function get(int $id): ReceiptView
    {
        return $this->receipts->find($id) ?? throw new UserError('Чек не найден или уже удалён.');
    }

    /**
     * Stores the reviewed positions and records the expense anew, so the balance always matches the receipt.
     */
    public function save(int $id, ReceiptForm $form): ReceiptView
    {
        $receipt = $this->get($id);

        $this->connection->transactional(function () use ($receipt, $form): void {
            $this->receipts->save($receipt->id, $form);
            $this->dropExpenses($receipt);
            $this->expenses->linkReceipt($this->record($form), $receipt->id);
        });

        return $this->get($id);
    }

    public function delete(int $id): void
    {
        $receipt = $this->get($id);

        $this->connection->transactional(function () use ($receipt): void {
            $this->dropExpenses($receipt);
            $this->receipts->softDelete($receipt->id);
        });
    }

    /**
     * @return list<int> ids of the recorded expenses
     */
    private function record(ReceiptForm $form): array
    {
        $content = $form->content;
        $parsed  = new ParsedExpense(
            $content->total(),
            $content->purchasedAt,
            [$this->purchaseCategoryCode],
            $form->split,
            $content->store,
            true,
        );
        $uuid     = Uuid::v4()->toRfc4122();
        $recorded = $form->payerId === null
            ? $this->jointRecorder->record($parsed, $uuid)
            : [$this->recorder->record($this->payer($form->payerId), $parsed, $uuid)];

        return array_map(static fn (RecordedExpense $expense): int => $expense->expense->id, $recorded);
    }

    private function payer(int $id): Participant
    {
        return $this->participants->find($id) ?? throw new UserError('Выбери, кто платил.');
    }

    private function dropExpenses(ReceiptView $receipt): void
    {
        foreach ($receipt->expenseIds as $expenseId) {
            $this->entries->softDelete(LedgerTable::Expense, $expenseId);
        }
    }
}
