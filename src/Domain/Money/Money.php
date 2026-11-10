<?php

declare(strict_types=1);

namespace App\Domain\Money;

final readonly class Money
{
    private const int MAX_DIGITS = 15;

    private function __construct(
        public int $minor,
        public Currency $currency,
    ) {
    }

    public static function ofMinor(int $minor, Currency $currency): self
    {
        return new self($minor, $currency);
    }

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    public static function fromDecimal(string $amount, Currency $currency): self
    {
        if (preg_match('/^([+-]?)(\d+)(?:[.,](\d+))?$/', $amount, $matches) !== 1) {
            throw InvalidAmountException::malformed($amount);
        }

        $sign     = $matches[1] === '-' ? -1 : 1;
        $integral = ltrim($matches[2], '0');
        $fraction = rtrim($matches[3] ?? '', '0');

        if (strlen($fraction) > $currency->exponent) {
            throw InvalidAmountException::tooPrecise($amount, $currency);
        }

        $digits = $integral . str_pad($fraction, $currency->exponent, '0');

        if (strlen($digits) > self::MAX_DIGITS) {
            throw InvalidAmountException::tooLarge($amount);
        }

        return new self($sign * (int) $digits, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::checked($this->minor + $other->minor), $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::checked($this->minor - $other->minor), $this->currency);
    }

    public function negate(): self
    {
        return new self(self::checked(-$this->minor), $this->currency);
    }

    public function abs(): self
    {
        return $this->isNegative() ? $this->negate() : $this;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency->equals($other->currency) && $this->minor === $other->minor;
    }

    public function toDecimal(): string
    {
        $exponent = $this->currency->exponent;
        $digits   = (string) abs($this->minor);
        $sign     = $this->minor < 0 ? '-' : '';

        if ($exponent === 0) {
            return $sign . $digits;
        }

        $digits = str_pad($digits, $exponent + 1, '0', STR_PAD_LEFT);

        return $sign . substr($digits, 0, -$exponent) . '.' . substr($digits, -$exponent);
    }

    private function assertSameCurrency(self $other): void
    {
        if (!$this->currency->equals($other->currency)) {
            throw CurrencyMismatchException::between($this->currency, $other->currency);
        }
    }

    private static function checked(int|float $value): int
    {
        if (!is_int($value)) {
            throw InvalidAmountException::overflow();
        }

        return $value;
    }
}
