<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure;

use App\Application\Rates\RateQuote;
use App\Application\Rates\RateSourceException;
use App\Infrastructure\Rates\CbrRateSource;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CbrRateSourceTest extends TestCase
{
    private const string XML = '<?xml version="1.0" encoding="windows-1251"?>'
        . '<ValCurs Date="10.11.2026" name="Foreign Currency Market">'
        . '<Valute ID="R01060"><NumCode>051</NumCode><CharCode>AMD</CharCode><Nominal>100</Nominal>'
        . '<Value>22,9597</Value></Valute>'
        . '<Valute ID="R01235"><NumCode>840</NumCode><CharCode>USD</CharCode><Nominal>1</Nominal>'
        . '<Value>82,7536</Value></Valute>'
        . '<Valute ID="R01239"><NumCode>978</NumCode><CharCode>EUR</CharCode><Nominal>1</Nominal>'
        . '<Value>92,3944</Value></Valute>'
        . '<Valute ID="BAD"><CharCode>X1</CharCode><Nominal>1</Nominal><Value>abc</Value></Valute>'
        . '</ValCurs>';

    public function testRebasesRubleQuotesToDram(): void
    {
        $requested = [];
        $client    = new MockHttpClient(static function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = $method . ' ' . $url;

            return new MockResponse(self::XML);
        }, 'https://www.cbr.ru');

        $snapshot = (new CbrRateSource($client))->fetch(new DateTimeImmutable('2026-11-10'));
        $rates    = [];

        foreach ($snapshot->quotes as $quote) {
            self::assertInstanceOf(RateQuote::class, $quote);
            $rates[$quote->currencyCode] = $quote->rateToBase;
        }

        self::assertSame(['GET https://www.cbr.ru/scripts/XML_daily.asp?date_req=10/11/2026'], $requested);
        self::assertSame('2026-11-10', $snapshot->effectiveDate->format('Y-m-d'));
        self::assertSame(['RUB' => '4.35545760', 'USD' => '360.42979655', 'EUR' => '402.41989224'], $rates);
    }

    public function testFailsWithoutDram(): void
    {
        $this->expectException(RateSourceException::class);

        (new CbrRateSource(new MockHttpClient()))->parse('<ValCurs Date="10.11.2026"></ValCurs>');
    }
}
