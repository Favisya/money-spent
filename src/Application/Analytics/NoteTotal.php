<?php

declare(strict_types=1);

namespace App\Application\Analytics;

final readonly class NoteTotal
{
    public function __construct(
        public string $note,
        public int $totalMinor,
        public int $count,
    ) {
    }
}
