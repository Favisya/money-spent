<?php

declare(strict_types=1);

namespace App\Domain\Money;

use InvalidArgumentException;

final readonly class Currency
{
    private const int MAX_EXPONENT = 8;

    public function __construct(
        public string $code,
        public int $exponent,
    ) {
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid currency code "%s".', $code));
        }

        if ($exponent < 0 || $exponent > self::MAX_EXPONENT) {
            throw new InvalidArgumentException(sprintf('Invalid exponent %d for %s.', $exponent, $code));
        }
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }
}
