<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\Import\PayerGuesser;
use App\Application\Participant\Participant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayerGuesserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?int}>
     */
    public static function lineProvider(): iterable
    {
        yield 'genitive form' => ['-2500 руб от алисы', 1];
        yield 'nominative' => ['18000 бензин Борис', 2];
        yield 'yo in the name' => ['500 кофе Артём', 3];
        yield 'nobody mentioned' => ['17000 продукты', null];
        yield 'both mentioned' => ['3000 ужин алиса и борис', null];
        yield 'short name skipped' => ['100 ян', null];
    }

    #[DataProvider('lineProvider')]
    public function testGuess(string $line, ?int $expectedId): void
    {
        $participants = [
            new Participant(1, null, 'Алиса'),
            new Participant(2, null, 'Борис'),
            new Participant(3, null, 'Артём'),
            new Participant(4, null, 'Ян'),
        ];

        self::assertSame($expectedId, (new PayerGuesser())->guess($line, $participants)?->id);
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function noteProvider(): iterable
    {
        yield 'preposition and name' => ['такси от бориса', 'такси'];
        yield 'name alone' => ['ужин борис кафе', 'ужин кафе'];
        yield 'only the mention' => ['от Бориса', null];
        yield 'english preposition and name' => ['taxi from бориса', 'taxi'];
        yield 'other words untouched' => ['борщ', 'борщ'];
        yield 'no note' => [null, null];
    }

    #[DataProvider('noteProvider')]
    public function testStripMention(?string $note, ?string $expected): void
    {
        self::assertSame($expected, (new PayerGuesser())->stripMention($note, new Participant(2, null, 'Борис')));
    }
}
