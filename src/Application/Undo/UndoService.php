<?php

declare(strict_types=1);

namespace App\Application\Undo;

use App\Application\LedgerEntry;
use App\Application\Participant\Participant;
use App\Infrastructure\Persistence\LedgerEntryRepository;
use App\Infrastructure\Persistence\LedgerTable;

final readonly class UndoService
{
    public function __construct(
        private LedgerEntryRepository $entries,
    ) {
    }

    /**
     * Soft-deletes the most recent expense or settlement created by the participant.
     */
    public function undoLast(Participant $author): ?LedgerEntry
    {
        $latest = $this->entries->latestActiveByAuthor($author->id, ...LedgerTable::cases());

        if ($latest === null) {
            return null;
        }

        $this->entries->softDelete($latest->table, $latest->id);

        return $latest;
    }
}
