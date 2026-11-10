<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Rates\RateQuote;
use App\Application\Rates\RateSnapshot;
use App\Application\Rates\RateSource;
use DateTimeImmutable;

final class StaticRateSource implements RateSource
{
    /** @var list<string> */
    public array $requestedDates = [];

    public function name(): string
    {
        return 'static';
    }

    public function fetch(DateTimeImmutable $date): RateSnapshot
    {
        $this->requestedDates[] = $date->format('Y-m-d');

        return new RateSnapshot($date, [
            new RateQuote('EUR', '400'),
            new RateQuote('RUB', '4.5'),
            new RateQuote('USD', '387.5'),
        ]);
    }
}
