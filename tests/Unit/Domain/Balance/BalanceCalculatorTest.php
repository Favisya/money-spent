<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Balance;

use App\Domain\Balance\BalanceCalculator;
use App\Domain\Balance\Debt;
use App\Domain\Balance\LedgerExpense;
use App\Domain\Balance\LedgerSettlement;
use App\Domain\Split\SplitCalculator;
use App\Domain\Split\SplitType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BalanceCalculatorTest extends TestCase
{
    private const int ALICE = 1;
    private const int BOB   = 2;
    private const int CAROL = 3;

    private BalanceCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new BalanceCalculator(new SplitCalculator());
    }

    public function testNoEntriesMeansSettled(): void
    {
        $balance = $this->calculator->calculate([self::ALICE, self::BOB], [], []);

        self::assertTrue($balance->isSettled());
        self::assertSame([self::ALICE => 0, self::BOB => 0], $balance->netByParticipant);
    }

    /**
     * @return iterable<string, array{list<LedgerExpense>, list<LedgerSettlement>, list<array{int, int, int}>}>
     */
    public static function twoParticipantsProvider(): iterable
    {
        yield 'equal expense' => [
            [new LedgerExpense(self::ALICE, 17000, SplitType::Equal)],
            [],
            [[self::BOB, self::ALICE, 8500]],
        ];
        yield 'payer only is not shared' => [
            [new LedgerExpense(self::ALICE, 17000, SplitType::PayerOnly)],
            [],
            [],
        ];
        yield 'other only is fully owed' => [
            [new LedgerExpense(self::ALICE, 3000, SplitType::OtherOnly)],
            [],
            [[self::BOB, self::ALICE, 3000]],
        ];
        yield 'mutual expenses net out' => [
            [
                new LedgerExpense(self::ALICE, 10000, SplitType::Equal),
                new LedgerExpense(self::BOB, 4000, SplitType::Equal),
            ],
            [],
            [[self::BOB, self::ALICE, 3000]],
        ];
        yield 'settlement clears the debt' => [
            [new LedgerExpense(self::ALICE, 17000, SplitType::Equal)],
            [new LedgerSettlement(self::BOB, self::ALICE, 8500)],
            [],
        ];
        yield 'partial settlement reduces the debt' => [
            [new LedgerExpense(self::ALICE, 17000, SplitType::Equal)],
            [new LedgerSettlement(self::BOB, self::ALICE, 5000)],
            [[self::BOB, self::ALICE, 3500]],
        ];
        yield 'overpayment flips direction' => [
            [new LedgerExpense(self::ALICE, 17000, SplitType::Equal)],
            [new LedgerSettlement(self::BOB, self::ALICE, 10000)],
            [[self::ALICE, self::BOB, 1500]],
        ];
        yield 'refund reduces the debt' => [
            [
                new LedgerExpense(self::ALICE, 10000, SplitType::Equal),
                new LedgerExpense(self::ALICE, -2000, SplitType::Equal),
            ],
            [],
            [[self::BOB, self::ALICE, 4000]],
        ];
        yield 'odd amount payer absorbs the unit' => [
            [new LedgerExpense(self::ALICE, 1001, SplitType::Equal)],
            [],
            [[self::BOB, self::ALICE, 500]],
        ];
    }

    /**
     * @param list<LedgerExpense>          $expenses
     * @param list<LedgerSettlement>       $settlements
     * @param list<array{int, int, int}>   $expectedDebts
     */
    #[DataProvider('twoParticipantsProvider')]
    public function testTwoParticipants(array $expenses, array $settlements, array $expectedDebts): void
    {
        $balance = $this->calculator->calculate([self::ALICE, self::BOB], $expenses, $settlements);

        self::assertSame($expectedDebts, self::flatten($balance->debts));
        self::assertSame(0, array_sum($balance->netByParticipant));
        self::assertSame($expectedDebts === [], $balance->isSettled());
    }

    public function testSettlingTheReportedDebtZeroesTheBalance(): void
    {
        $participants = [self::ALICE, self::BOB];
        $expenses     = [
            new LedgerExpense(self::ALICE, 17000, SplitType::Equal),
            new LedgerExpense(self::BOB, 9700, SplitType::Equal),
            new LedgerExpense(self::BOB, 7463, SplitType::OtherOnly),
        ];

        $debt  = $this->calculator->calculate($participants, $expenses, [])->debts[0];
        $after = $this->calculator->calculate(
            $participants,
            $expenses,
            [new LedgerSettlement($debt->debtorId, $debt->creditorId, $debt->amountBaseMinor)],
        );

        self::assertTrue($after->isSettled());
    }

    public function testThreeParticipants(): void
    {
        $balance = $this->calculator->calculate(
            [self::ALICE, self::BOB, self::CAROL],
            [
                new LedgerExpense(self::ALICE, 9000, SplitType::Equal),
                new LedgerExpense(self::BOB, 3000, SplitType::Equal),
            ],
            [],
        );

        self::assertSame(
            [[self::CAROL, self::ALICE, 4000], [self::BOB, self::ALICE, 1000]],
            self::flatten($balance->debts),
        );
    }

    public function testUnknownSettlementParticipant(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculate([self::ALICE, self::BOB], [], [new LedgerSettlement(self::ALICE, self::CAROL, 1)]);
    }

    public function testUnknownPayer(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculate(
            [self::ALICE, self::BOB],
            [new LedgerExpense(self::CAROL, 100, SplitType::Equal)],
            [],
        );
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function invalidSettlementProvider(): iterable
    {
        yield 'same participant' => [self::ALICE, self::ALICE, 100];
        yield 'zero amount' => [self::ALICE, self::BOB, 0];
        yield 'negative amount' => [self::ALICE, self::BOB, -100];
    }

    #[DataProvider('invalidSettlementProvider')]
    public function testInvalidSettlement(int $from, int $to, int $amount): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LedgerSettlement($from, $to, $amount);
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function invalidDebtProvider(): iterable
    {
        yield 'same participant' => [self::ALICE, self::ALICE, 100];
        yield 'zero amount' => [self::ALICE, self::BOB, 0];
    }

    #[DataProvider('invalidDebtProvider')]
    public function testInvalidDebt(int $debtor, int $creditor, int $amount): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Debt($debtor, $creditor, $amount);
    }

    /**
     * @param list<Debt> $debts
     *
     * @return list<array{int, int, int}>
     */
    private static function flatten(array $debts): array
    {
        return array_map(
            static fn (Debt $debt): array => [$debt->debtorId, $debt->creditorId, $debt->amountBaseMinor],
            $debts,
        );
    }
}
