<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Split;

use App\Domain\Split\SplitType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SplitTypeTest extends TestCase
{
    /**
     * @return iterable<string, array{SplitType, int, list<int>, ?int}>
     */
    public static function ownerProvider(): iterable
    {
        yield 'equal is shared' => [SplitType::Equal, 1, [1, 2], null];
        yield 'payer only belongs to payer' => [SplitType::PayerOnly, 2, [1, 2], 2];
        yield 'other only belongs to the other' => [SplitType::OtherOnly, 1, [1, 2], 2];
        yield 'other only among three is shared by the rest' => [SplitType::OtherOnly, 1, [1, 2, 3], null];
        yield 'equal with one participant is personal' => [SplitType::Equal, 5, [5], 5];
    }

    /**
     * @param list<int> $participants
     */
    #[DataProvider('ownerProvider')]
    public function testOwner(SplitType $split, int $payer, array $participants, ?int $expected): void
    {
        self::assertSame($expected, $split->ownerId($payer, $participants));
    }
}
