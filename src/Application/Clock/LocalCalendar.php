<?php

declare(strict_types=1);

namespace App\Application\Clock;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

final readonly class LocalCalendar
{
    private DateTimeZone $zone;

    public function __construct(
        private ClockInterface $clock,
        string $timezone,
    ) {
        $this->zone = new DateTimeZone($timezone);
    }

    public function today(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone($this->zone)->setTime(0, 0);
    }

    public function zone(): DateTimeZone
    {
        return $this->zone;
    }
}
