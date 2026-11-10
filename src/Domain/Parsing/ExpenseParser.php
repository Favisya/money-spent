<?php

declare(strict_types=1);

namespace App\Domain\Parsing;

use App\Domain\Money\Currency;
use App\Domain\Money\InvalidAmountException;
use App\Domain\Money\Money;
use App\Domain\Split\SplitType;
use DateTimeImmutable;

final readonly class ExpenseParser
{
    private const int MAX_LENGTH      = 500;
    private const int NOTE_LENGTH     = 255;
    private const int PREFIX_MATCH_AT = 4;
    private const string TRIM_PATTERN = '/^[,;:!?()"\'«»]+|[,;:!?()"\'«».]+$/u';

    private const string AMOUNT_PATTERN = '/^(?<pre>\p{Sc}?)(?<num>[+-]?\d+(?:[.,](?<frac>\d+))?)'
        . '(?<suf>[\p{L}\p{Sc}]*)$/u';
    private const string DATE_PATTERN = '/^(?<d>\d{1,2})\.(?<m>\d{1,2})(?:\.(?<y>\d{4}|\d{2}))?$/';

    private const array RELATIVE_DAYS = [
        'сегодня'   => 0,
        'today'     => 0,
        'вчера'     => 1,
        'yesterday' => 1,
        'позавчера' => 2,
    ];

    public function __construct(
        private ParserDictionary $dictionary,
    ) {
    }

    /**
     * @throws ExpenseParseException
     */
    public function parse(string $text, DateTimeImmutable $today): ParsedExpense
    {
        $text = trim($text);

        if ($text === '') {
            throw new ExpenseParseException(ParseError::Empty);
        }

        if (mb_strlen($text) > self::MAX_LENGTH) {
            throw new ExpenseParseException(ParseError::TooLong, ['max' => (string) self::MAX_LENGTH]);
        }

        $today                            = $today->setTime(0, 0);
        $tokens                           = self::attachSigns(preg_split('/\s+/u', $text) ?: []);
        [$amountIndex, $match, $currency] = $this->locateAmount($tokens);
        unset($tokens[$amountIndex]);

        $spentAt    = null;
        $split      = null;
        $categories = [];
        $noteTokens = [];

        foreach ($tokens as $token) {
            $word = self::normalize($token);

            if ($currency === null && isset($this->dictionary->currencyAliases[$word])) {
                $currency = $this->dictionary->currencyAliases[$word];

                continue;
            }

            if ($spentAt === null) {
                $spentAt = $this->parseDate($word, $today);

                if ($spentAt !== null) {
                    continue;
                }
            }

            if ($split === null && isset($this->dictionary->splitAliases[$word])) {
                $split = $this->dictionary->splitAliases[$word];

                continue;
            }

            $matched = $this->matchCategories($word);

            if ($matched !== []) {
                $categories   = array_values(array_unique([...$categories, ...$matched]));
                $noteTokens[] = self::clean($token);

                continue;
            }

            $noteTokens[] = $token;
        }

        return new ParsedExpense(
            $this->buildAmount($match, $currency ?? $this->dictionary->defaultCurrency),
            $spentAt ?? $today,
            $categories,
            $split ?? SplitType::Equal,
            $this->buildNote($noteTokens),
            $spentAt !== null,
        );
    }

    /**
     * Recognizes a line that holds nothing but a date ("10.11", "вчера"), as day headers in a free-form note.
     *
     * @throws ExpenseParseException when the line looks like a date but is not a valid one
     */
    public function parseDateLine(string $text, DateTimeImmutable $today): ?DateTimeImmutable
    {
        $tokens = preg_split('/\s+/u', trim($text)) ?: [];

        if (count($tokens) !== 1) {
            return null;
        }

        return $this->parseDate(self::normalize($tokens[0]), $today->setTime(0, 0));
    }

    /**
     * @param array<int, string> $tokens
     *
     * @return array{int, array{num: string, frac?: string}, ?Currency}
     */
    private function locateAmount(array $tokens): array
    {
        foreach ($tokens as $index => $token) {
            if (preg_match(self::AMOUNT_PATTERN, self::clean($token), $match) !== 1) {
                continue;
            }

            $affix = mb_strtolower($match['pre'] . $match['suf']);

            if ($affix === '') {
                return [$index, $match, null];
            }

            if (isset($this->dictionary->currencyAliases[$affix])) {
                return [$index, $match, $this->dictionary->currencyAliases[$affix]];
            }
        }

        throw new ExpenseParseException(ParseError::NoAmount);
    }

    /**
     * @param array{num: string, frac?: string} $match
     */
    private function buildAmount(array $match, Currency $currency): Money
    {
        $fraction = rtrim($match['frac'] ?? '', '0');

        if (strlen($fraction) > $currency->exponent) {
            throw new ExpenseParseException(ParseError::TooPrecise, [
                'amount'   => $match['num'],
                'currency' => $currency->code,
                'digits'   => (string) $currency->exponent,
            ]);
        }

        try {
            $amount = Money::fromDecimal($match['num'], $currency);
        } catch (InvalidAmountException) {
            throw new ExpenseParseException(ParseError::AmountTooLarge, ['amount' => $match['num']]);
        }

        if ($amount->isZero()) {
            throw new ExpenseParseException(ParseError::ZeroAmount);
        }

        return $amount;
    }

    private function parseDate(string $word, DateTimeImmutable $today): ?DateTimeImmutable
    {
        if (isset(self::RELATIVE_DAYS[$word])) {
            return $today->modify(sprintf('-%d days', self::RELATIVE_DAYS[$word]));
        }

        if (preg_match(self::DATE_PATTERN, $word, $match) !== 1) {
            return null;
        }

        $day          = (int) $match['d'];
        $month        = (int) $match['m'];
        $explicitYear = isset($match['y']);
        $year         = $explicitYear ? (int) $match['y'] : (int) $today->format('Y');
        $year         = $year < 100 ? 2000 + $year : $year;

        if (!checkdate($month, $day, $year)) {
            throw new ExpenseParseException(ParseError::InvalidDate, ['date' => $word]);
        }

        $date = $today->setDate($year, $month, $day);

        if ($date <= $today) {
            return $date;
        }

        if ($explicitYear || !checkdate($month, $day, $year - 1)) {
            throw new ExpenseParseException(ParseError::FutureDate, ['date' => $word]);
        }

        return $date->setDate($year - 1, $month, $day);
    }

    /**
     * @return list<string>
     */
    private function matchCategories(string $word): array
    {
        $codes = [];

        foreach ($this->dictionary->categories as $category) {
            foreach ($category->keywords as $keyword) {
                if (self::keywordMatches(self::normalize($keyword), $word)) {
                    $codes[] = $category->code;

                    break;
                }
            }
        }

        return $codes;
    }

    /**
     * @param list<string> $tokens
     */
    private function buildNote(array $tokens): ?string
    {
        $note = trim(implode(' ', $tokens));

        return $note === '' ? null : mb_substr($note, 0, self::NOTE_LENGTH);
    }

    private static function keywordMatches(string $keyword, string $word): bool
    {
        if ($keyword === '') {
            return false;
        }

        if ($keyword === $word) {
            return true;
        }

        return mb_strlen($keyword) >= self::PREFIX_MATCH_AT && str_starts_with($word, $keyword);
    }

    /**
     * "+ 5000" and "− 5000" become "+5000" and "-5000", so a sign typed apart from the number still counts.
     *
     * @param list<string> $tokens
     *
     * @return array<int, string>
     */
    private static function attachSigns(array $tokens): array
    {
        $merged = [];
        $count  = count($tokens);

        for ($i = 0; $i < $count; ++$i) {
            $sign = str_replace('−', '-', $tokens[$i]);

            if (($sign === '+' || $sign === '-') && preg_match('/^\d/', $tokens[$i + 1] ?? '') === 1) {
                $merged[] = $sign . $tokens[++$i];

                continue;
            }

            $merged[] = $tokens[$i];
        }

        return $merged;
    }

    private static function normalize(string $token): string
    {
        return str_replace('ё', 'е', mb_strtolower(self::clean($token)));
    }

    private static function clean(string $token): string
    {
        return preg_replace(self::TRIM_PATTERN, '', $token) ?? $token;
    }
}
