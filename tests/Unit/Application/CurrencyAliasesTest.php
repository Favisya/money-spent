<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\Currency\CurrencyAliases;
use App\Application\UserError;
use PHPUnit\Framework\TestCase;

final class CurrencyAliasesTest extends TestCase
{
    public function testWordsAreLowerCasedAndUnique(): void
    {
        self::assertSame(['лари', '₾', 'gel'], CurrencyAliases::parse(" Лари, ₾;  GEL лари\n"));
        self::assertSame([], CurrencyAliases::parse('  '));
    }

    public function testDigitsAndPunctuationAreRefused(): void
    {
        $this->expectException(UserError::class);
        $this->expectExceptionMessage('Слово «50gel» не подойдёт');

        CurrencyAliases::parse('лари, 50gel');
    }
}
