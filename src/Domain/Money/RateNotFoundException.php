<?php

declare(strict_types=1);

namespace App\Domain\Money;

use DateTimeImmutable;
use DomainException;

final class RateNotFoundException extends DomainException
{
    public static function for(Currency $currency, DateTimeImmutable $date): self
    {
        return new self(sprintf('No %s rate on or before %s.', $currency->code, $date->format('Y-m-d')));
    }
}
