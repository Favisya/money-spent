<?php

declare(strict_types=1);

namespace App\Application\Balance;

use App\Application\Reference\CurrencyCatalog;
use App\Domain\Balance\BalanceCalculator;
use App\Infrastructure\Persistence\ExpenseRepository;
use App\Infrastructure\Persistence\LedgerEntryRepository;
use App\Infrastructure\Persistence\ParticipantRepository;
use App\Infrastructure\Persistence\SettlementRepository;

final readonly class BalanceService
{
    public function __construct(
        private ParticipantRepository $participants,
        private ExpenseRepository $expenses,
        private SettlementRepository $settlements,
        private LedgerEntryRepository $entries,
        private BalanceCalculator $calculator,
        private CurrencyCatalog $currencies,
    ) {
    }

    public function current(): BalanceReport
    {
        $participants = [];

        foreach ($this->participants->all() as $participant) {
            $participants[$participant->id] = $participant;
        }

        $balance = $this->calculator->calculate(
            array_keys($participants),
            $this->expenses->ledger(),
            $this->settlements->ledger(),
        );

        return new BalanceReport($balance, $participants, $this->currencies->base(), $this->entries->countPending());
    }

}
