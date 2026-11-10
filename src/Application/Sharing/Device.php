<?php

declare(strict_types=1);

namespace App\Application\Sharing;

use DateTimeImmutable;

final readonly class Device
{
    public function __construct(
        public int $id,
        public int $participantId,
        public string $participantName,
        public string $name,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $lastSeenAt,
    ) {
    }
}
