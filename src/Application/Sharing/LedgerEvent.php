<?php

declare(strict_types=1);

namespace App\Application\Sharing;

final readonly class LedgerEvent
{
    public function __construct(
        public string $id,
        public string $title,
        public string $body,
    ) {
    }
}
