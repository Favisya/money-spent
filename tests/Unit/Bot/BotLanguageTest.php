<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bot;

use App\Application\Participant\Participant;
use App\Bot\Locale\BotLanguage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BotLanguageTest extends TestCase
{
    private BotLanguage $languages;

    protected function setUp(): void
    {
        $this->languages = new BotLanguage(['ru', 'en'], 'ru');
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function telegramProvider(): iterable
    {
        yield 'no language' => [null, 'ru'];
        yield 'russian' => ['ru', 'ru'];
        yield 'english' => ['en', 'en'];
        yield 'english region' => ['en-GB', 'en'];
        yield 'upper case, underscore' => [' EN_us ', 'en'];
        yield 'ukrainian' => ['uk', 'ru'];
        yield 'armenian' => ['hy', 'ru'];
        yield 'kazakh' => ['kk', 'ru'];
        yield 'german' => ['de', 'en'];
        yield 'brazilian portuguese' => ['pt-br', 'en'];
        yield 'empty' => ['', 'ru'];
        yield 'garbage' => ['<script>', 'ru'];
        yield 'too long subtag' => ['en-verylongsubtag', 'ru'];
    }

    #[DataProvider('telegramProvider')]
    public function testLanguageFollowsTelegram(?string $telegram, string $expected): void
    {
        self::assertSame($expected, $this->languages->forParticipant(new Participant(1, 1, 'Анна'), $telegram));
    }

    public function testPickedLanguageWins(): void
    {
        $participant = new Participant(1, 1, 'Анна', null, 'en');

        self::assertSame('en', $this->languages->forParticipant($participant, 'ru'));
        self::assertSame('en', $this->languages->forParticipant($participant));
    }

    public function testUnsupportedPickedLanguageIsIgnored(): void
    {
        self::assertSame('ru', $this->languages->forParticipant(new Participant(1, 1, 'Анна', null, 'fr'), 'uk'));
    }

    public function testNamesAreInTheLanguageItself(): void
    {
        self::assertSame('English', $this->languages->name('en'));
        self::assertSame('Русский', $this->languages->name('ru'));
    }

    public function testTelegramMenuLanguages(): void
    {
        $map = $this->languages->telegramLanguages();

        self::assertSame('ru', $map['ru']);
        self::assertSame('en', $map['en']);
        self::assertSame('ru', $map['uk']);
        self::assertArrayNotHasKey('de', $map);
        self::assertSame('en', $this->languages->fallback());
    }
}
