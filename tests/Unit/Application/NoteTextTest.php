<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\Expense\NoteText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoteTextTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, string, ?string}>
     */
    public static function provider(): iterable
    {
        yield 'keyword kept' => ['свет', 'Коммуналка', 'свет'];
        yield 'only the title' => ['Продукты', 'Продукты', null];
        yield 'title word dropped' => ['продукты ашан', 'Продукты', 'ашан'];
        yield 'yo and case' => ['ПРОЧЕЁ', 'Прочее', null];
        yield 'multi-word title' => ['кафе и бары', 'Кафе и бары', null];
        yield 'whitespace collapsed' => ["  газ \t за   октябрь ", 'Коммуналка', 'газ за октябрь'];
        yield 'empty' => ['   ', 'Прочее', null];
        yield 'none' => [null, 'Прочее', null];
    }

    #[DataProvider('provider')]
    public function testBesides(?string $note, string $title, ?string $expected): void
    {
        self::assertSame($expected, NoteText::besides($note, $title));
    }
}
