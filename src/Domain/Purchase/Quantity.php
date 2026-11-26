<?php

declare(strict_types=1);

namespace App\Domain\Purchase;

use InvalidArgumentException;

/**
 * A positive amount of goods: whole pieces or a decimal weight/volume with up to three decimals.
 */
final readonly class Quantity
{
    public const int SCALE = 3;

    private const string PATTERN = '/^\d{1,9}(?:[.,]\d+)?$/';

    /** @var numeric-string */
    public string $value;

    private function __construct(
        string $value,
        public Unit $unit,
    ) {
        $normalized = str_replace(',', '.', trim($value));

        if (!is_numeric($normalized) || preg_match(self::PATTERN, $normalized) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid quantity "%s".', $value));
        }

        $scaled = bcadd($normalized, '0', self::SCALE);

        if (bccomp($scaled, '0', self::SCALE) <= 0) {
            throw new InvalidArgumentException('Quantity must be positive.');
        }

        if ($unit === Unit::Piece && bccomp($scaled, bcadd($scaled, '0', 0), self::SCALE) !== 0) {
            throw new InvalidArgumentException('Pieces are counted in whole numbers.');
        }

        $this->value = $scaled;
    }

    public static function of(string $value, Unit $unit): self
    {
        return new self($value, $unit);
    }

    public static function one(): self
    {
        return new self('1', Unit::Piece);
    }

    /**
     * "1.5", "2", "0.25" — without trailing zeros.
     */
    public function format(): string
    {
        return rtrim(rtrim($this->value, '0'), '.');
    }
}
