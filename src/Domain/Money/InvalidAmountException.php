<?php

declare(strict_types=1);

namespace App\Domain\Money;

use DomainException;

final class InvalidAmountException extends DomainException
{
    public static function malformed(string $amount): self
    {
        return new self(sprintf('Malformed amount "%s".', $amount));
    }

    public static function tooPrecise(string $amount, Currency $currency): self
    {
        return new self(sprintf(
            'Amount "%s" has more than %d decimal places allowed for %s.',
            $amount,
            $currency->exponent,
            $currency->code,
        ));
    }

    public static function tooLarge(string $amount): self
    {
        return new self(sprintf('Amount "%s" is too large.', $amount));
    }

    public static function overflow(): self
    {
        return new self('Integer overflow in money arithmetic.');
    }
}
