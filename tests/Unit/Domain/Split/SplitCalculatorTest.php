<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Split;

use App\Domain\Split\SplitCalculator;
use App\Domain\Split\SplitType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SplitCalculatorTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, SplitType, list<int>, array<int, int>}>
     */
    public static function sharesProvider(): iterable
    {
        yield 'equal even' => [1, 1000, SplitType::Equal, [1, 2], [1 => 500, 2 => 500]];
        yield 'equal odd payer absorbs' => [2, 1001, SplitType::Equal, [1, 2], [1 => 500, 2 => 501]];
        yield 'equal negative odd' => [1, -1001, SplitType::Equal, [1, 2], [1 => -501, 2 => -500]];
        yield 'payer only' => [1, 1000, SplitType::PayerOnly, [1, 2], [1 => 1000, 2 => 0]];
        yield 'other only' => [1, 1000, SplitType::OtherOnly, [1, 2], [1 => 0, 2 => 1000]];
        yield 'three equal remainder 2' => [3, 1001, SplitType::Equal, [1, 2, 3], [1 => 334, 2 => 333, 3 => 334]];
        yield 'three other only' => [2, 1001, SplitType::OtherOnly, [3, 1, 2], [1 => 501, 2 => 0, 3 => 500]];
        yield 'single participant equal' => [7, 999, SplitType::Equal, [7], [7 => 999]];
        yield 'zero amount' => [1, 0, SplitType::Equal, [1, 2], [1 => 0, 2 => 0]];
    }

    /**
     * @param list<int>       $participants
     * @param array<int, int> $expected
     */
    #[DataProvider('sharesProvider')]
    public function testShares(int $payer, int $amount, SplitType $split, array $participants, array $expected): void
    {
        $shares = (new SplitCalculator())->shares($payer, $amount, $split, $participants);

        ksort($shares);
        self::assertSame($expected, $shares);
        self::assertSame($amount, array_sum($shares));
    }

    /**
     * @return iterable<string, array{int, SplitType, list<int>}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'payer is not participant' => [3, SplitType::Equal, [1, 2]];
        yield 'no participants' => [1, SplitType::Equal, []];
        yield 'duplicate participants' => [1, SplitType::Equal, [1, 1, 2]];
        yield 'other only without others' => [1, SplitType::OtherOnly, [1]];
    }

    /**
     * @param list<int> $participants
     */
    #[DataProvider('invalidProvider')]
    public function testInvalidInput(int $payer, SplitType $split, array $participants): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SplitCalculator())->shares($payer, 100, $split, $participants);
    }
}
