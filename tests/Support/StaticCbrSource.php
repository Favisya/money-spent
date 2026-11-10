<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Rates\RateQuote;
use App\Application\Rates\RateSnapshot;
use App\Application\Rates\RateSource;
use DateTimeImmutable;

/**
 * Stand-in for the Central Bank of Russia: AMD per unit, as CbrRateSource reports after re-basing.
 */
final class StaticCbrSource implements RateSource
{
    public function name(): string
    {
        return 'cbr';
    }

    public function fetch(DateTimeImmutable $date): RateSnapshot
    {
        return new RateSnapshot($date, [
            new RateQuote('RUB', '4.35546'),
            new RateQuote('USD', '360.42800'),
            new RateQuote('EUR', '402.41700'),
            new RateQuote('GBP', '470.00000'),
        ]);
    }
}
