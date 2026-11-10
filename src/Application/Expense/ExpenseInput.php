<?php

declare(strict_types=1);

namespace App\Application\Expense;

use App\Application\UserError;
use App\Domain\Money\Currency;
use App\Domain\Money\InvalidAmountException;
use App\Domain\Money\Money;
use DateTimeImmutable;

/**
 * Values typed into the inline editors of an expense row.
 */
final class ExpenseInput
{
    private const string DAY_PATTERN    = '/^(?<d>\d{1,2})[.\/-](?<m>\d{1,2})(?:[.\/-](?<y>\d{2}|\d{4}))?$/';
    private const string AMOUNT_PATTERN = '/^\d+(?:[.,]\d+)?$/';
    private const string SPACES         = "/[\s\x{00A0}\x{202F}]+/u";
    private const int FIRST_YEAR        = 2000;
    private const int LAST_YEAR         = 2100;
    private const int CENTURY           = 2000;

    /**
     * "8.11", "08.11.2026" or "08.11.26"; a missing year is taken from the current date of the expense.
     */
    public static function day(string $text, DateTimeImmutable $current): DateTimeImmutable
    {
        if (preg_match(self::DAY_PATTERN, trim($text), $match) !== 1) {
            throw new UserError('Дата — в виде 08.11 или 08.11.2026.');
        }

        $day   = (int) $match['d'];
        $month = (int) $match['m'];
        $year  = ($match['y'] ?? '') === '' ? (int) $current->format('Y') : (int) $match['y'];
        $year  = $year < 100 ? self::CENTURY + $year : $year;

        if (!checkdate($month, $day, $year) || $year < self::FIRST_YEAR || $year > self::LAST_YEAR) {
            throw new UserError('Такой даты нет.');
        }

        return $current->setDate($year, $month, $day)->setTime(0, 0);
    }

    /**
     * A positive amount such as "17 000", "18,5" or "18.50".
     */
    public static function amount(string $text, Currency $currency): Money
    {
        $text = (string) preg_replace(self::SPACES, '', $text);

        if (preg_match(self::AMOUNT_PATTERN, $text) !== 1) {
            throw new UserError('Сумма — число, например 17000 или 18,5.');
        }

        try {
            $amount = Money::fromDecimal($text, $currency);
        } catch (InvalidAmountException) {
            throw new UserError(sprintf(
                'В %s не больше %d знаков после запятой, и сумма не может быть такой большой.',
                $currency->code,
                $currency->exponent,
            ));
        }

        if ($amount->isZero()) {
            throw new UserError('Сумма должна быть больше нуля.');
        }

        return $amount;
    }
}
