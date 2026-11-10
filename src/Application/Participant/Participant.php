<?php

declare(strict_types=1);

namespace App\Application\Participant;

final readonly class Participant
{
    public function __construct(
        public int $id,
        public ?int $telegramId,
        public string $name,
    ) {
    }
}
