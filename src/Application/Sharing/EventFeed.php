<?php

declare(strict_types=1);

namespace App\Application\Sharing;

use App\Bot\View\MoneyFormatter;
use App\Infrastructure\Persistence\ExpenseRepository;
use App\Infrastructure\Persistence\LedgerEntryRepository;
use App\Infrastructure\Persistence\SettlementRepository;
use DateTimeImmutable;

/**
 * New expenses and transfers for desktop notifications: everything recorded since the cursor where someone else paid.
 * The first call only hands out a cursor, so opening the app never floods old entries.
 */
final readonly class EventFeed
{
    private const int LIMIT = 20;

    public function __construct(
        private ExpenseRepository $expenses,
        private SettlementRepository $settlements,
        private LedgerEntryRepository $entries,
        private MoneyFormatter $money,
    ) {
    }

    /**
     * @return array{cursor: DateTimeImmutable, events: list<LedgerEvent>}
     */
    public function since(?DateTimeImmutable $after, ?int $meId): array
    {
        $now = $this->entries->now();

        if ($after === null || $after > $now) {
            return ['cursor' => $now, 'events' => []];
        }

        $events = [];

        foreach ($this->expenses->createdBetween($after, $now, self::LIMIT) as $expense) {
            if ($expense->payerId === $meId) {
                continue;
            }

            $events[] = new LedgerEvent(
                'e' . $expense->id,
                sprintf('%s: %s', $expense->payerName, $this->money->format($expense->amount)),
                $expense->categoryTitle . ($expense->note === null ? '' : ' · ' . $expense->note),
            );
        }

        foreach ($this->settlements->createdBetween($after, $now, self::LIMIT) as $settlement) {
            if ($settlement->fromId === $meId) {
                continue;
            }

            $events[] = new LedgerEvent(
                's' . $settlement->id,
                sprintf('%s перевод(ит) %s', $settlement->fromName, $this->money->format($settlement->amount)),
                '→ ' . $settlement->toName . ($settlement->note === null ? '' : ' · ' . $settlement->note),
            );
        }

        return ['cursor' => $now, 'events' => $events];
    }
}
