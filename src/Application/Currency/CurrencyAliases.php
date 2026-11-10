<?php

declare(strict_types=1);

namespace App\Application\Currency;

use App\Application\UserError;

/**
 * Words that name a currency in the entry line, typed as "лари, gel ₾".
 */
final class CurrencyAliases
{
    private const string SEPARATORS = '/[\s,;]+/u';
    private const string WORD       = '/^[\p{L}\p{Sc}]{1,20}$/u';
    private const int MAX_ALIASES   = 20;

    /**
     * @return list<string> lower-case, unique, in the order typed
     */
    public static function parse(string $text): array
    {
        $words = preg_split(self::SEPARATORS, mb_strtolower(trim($text)), -1, PREG_SPLIT_NO_EMPTY);
        $words = array_values(array_unique($words === false ? [] : $words));

        if (count($words) > self::MAX_ALIASES) {
            throw new UserError('Не больше %max% слов для одной валюты.', ['%max%' => self::MAX_ALIASES]);
        }

        foreach ($words as $word) {
            if (preg_match(self::WORD, $word) !== 1) {
                throw new UserError(
                    'Слово «%word%» не подойдёт: только буквы или знак валюты, до 20 символов.',
                    ['%word%' => mb_substr($word, 0, 30)],
                );
            }
        }

        return $words;
    }
}
