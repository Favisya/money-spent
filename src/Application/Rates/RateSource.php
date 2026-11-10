<?php

declare(strict_types=1);

namespace App\Application\Rates;

use DateTimeImmutable;

interface RateSource
{
    public function name(): string;

    /**
     * Rates effective on the date. The source may answer with an earlier date when the requested one is not published.
     *
     * @throws RateSourceException
     */
    public function fetch(DateTimeImmutable $date): RateSnapshot;
}
