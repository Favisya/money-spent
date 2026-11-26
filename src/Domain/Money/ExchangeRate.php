<?php

declare(strict_types=1);

namespace App\Domain\Money;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ExchangeRate
{
    public const int SCALE = 8;

    /** @var numeric-string */
    public string $rate;

    public function __construct(
        public Currency $currency,
        public Currency $base,
        string $rate,
        public DateTimeImmutable $date,
    ) {
        $valid = is_numeric($rate)
            && preg_match('/^\d+(\.\d+)?$/', $rate) === 1
            && bccomp($rate, '0', self::SCALE) > 0;

        if (!$valid) {
            throw new InvalidArgumentException(sprintf('Invalid exchange rate "%s".', $rate));
        }

        if ($currency->equals($base) && bccomp($rate, '1', self::SCALE) !== 0) {
            throw new InvalidArgumentException('Rate of a currency to itself must be 1.');
        }

        $this->rate = bcadd($rate, '0', self::SCALE);
    }

    public static function identity(Currency $base, DateTimeImmutable $date): self
    {
        return new self($base, $base, '1', $date);
    }

    public function convert(Money $money): Money
    {
        if (!$money->currency->equals($this->currency)) {
            throw CurrencyMismatchException::between($money->currency, $this->currency);
        }

        $value = bcmul((string) $money->minor, $this->rate, DecimalRounding::WORK_SCALE);
        $shift = $this->base->exponent - $this->currency->exponent;
        $power = bcpow('10', (string) abs($shift), 0);
        $value = $shift >= 0
            ? bcmul($value, $power, DecimalRounding::WORK_SCALE)
            : bcdiv($value, $power, DecimalRounding::WORK_SCALE);

        return Money::ofMinor(DecimalRounding::halfAwayFromZero($value), $this->base);
    }

    /**
     * The inverse of convert(): how much of this rate's currency a base amount is worth.
     */
    public function convertFromBase(Money $money): Money
    {
        if (!$money->currency->equals($this->base)) {
            throw CurrencyMismatchException::between($money->currency, $this->base);
        }

        $value = bcdiv((string) $money->minor, $this->rate, DecimalRounding::WORK_SCALE);
        $shift = $this->currency->exponent - $this->base->exponent;
        $power = bcpow('10', (string) abs($shift), 0);
        $value = $shift >= 0
            ? bcmul($value, $power, DecimalRounding::WORK_SCALE)
            : bcdiv($value, $power, DecimalRounding::WORK_SCALE);

        return Money::ofMinor(DecimalRounding::halfAwayFromZero($value), $this->currency);
    }
}
