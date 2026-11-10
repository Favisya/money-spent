<?php

declare(strict_types=1);

namespace App\Application\Sharing;

use App\Bot\View\MoneyFormatter;
use App\Infrastructure\Persistence\ExpenseRepository;
use App\Infrastructure\Persistence\LedgerEntryRepository;
use App\Infrastructure\Persistence\SettlementRepository;
use DateTimeImmutable;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * New expenses and transfers for desktop notifications: everything recorded since the cursor where someone else paid.
 * The first call only hands out a cursor, so opening the app never floods old entries.
 * Texts follow the current language; seeded category titles are translated, participant names never.
 */
final readonly class EventFeed
{
    private const int LIMIT = 20;

    public function __construct(
        private ExpenseRepository $expenses,
        private SettlementRepository $settlements,
        private LedgerEntryRepository $entries,
        private MoneyFormatter $money,
        private TranslatorInterface $translator,
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
                $this->translator->trans($expense->categoryTitle)
                . ($expense->note === null ? '' : ' · ' . $expense->note),
            );
        }

        foreach ($this->settlements->createdBetween($after, $now, self::LIMIT) as $settlement) {
            if ($settlement->fromId === $meId) {
                continue;
            }

            $events[] = new LedgerEvent(
                's' . $settlement->id,
                $this->translator->trans('%name% перевод(ит) %amount%', [
                    '%name%'   => $settlement->fromName,
                    '%amount%' => $this->money->format($settlement->amount),
                ]),
                '→ ' . $settlement->toName . ($settlement->note === null ? '' : ' · ' . $settlement->note),
            );
        }

        return ['cursor' => $now, 'events' => $events];
    }
}
