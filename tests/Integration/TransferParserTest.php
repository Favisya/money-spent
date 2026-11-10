<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Participant\Participant;
use App\Application\Transfer\TransferIntent;
use App\Application\Transfer\TransferParser;
use App\Infrastructure\Persistence\ParticipantRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Transfer lines in Russian and English, with the verbs and currencies from the seeded reference data.
 */
final class TransferParserTest extends KernelTestCase
{
    private const string TODAY = '2026-11-10';

    private TransferParser $parser;
    private Participant $alice;
    private Participant $boris;

    protected function setUp(): void
    {
        $app       = self::bootKernel();
        $container = self::getContainer();
        $db        = $container->get(Connection::class);
        $db->executeStatement(
            'TRUNCATE telegram_update, settlement, expense, exchange_rate, participant, category '
            . 'RESTART IDENTITY CASCADE',
        );
        (new CommandTester((new Application($app))->find('app:seed')))->execute([]);

        $participants = $container->get(ParticipantRepository::class);
        $this->alice  = $participants->create('Alice', 3001);
        $this->boris  = $participants->create('Boris', 3002);
        $this->parser = $container->get(TransferParser::class);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function verbProvider(): iterable
    {
        yield 'sent' => ['boris sent 5000', true];
        yield 'transferred' => ['Transferred mom 10000', true];
        yield 'gave' => ['gave mom 500', true];
        yield 'repaid' => ['repaid 2000.', true];
        yield 'russian' => ['алиса скинула 5000', true];
        yield 'expense' => ['700 coffee personal', false];
        yield 'transfers category is not a verb' => ['700 transfers', false];
    }

    #[DataProvider('verbProvider')]
    public function testRecognizesTransferVerbs(string $line, bool $expected): void
    {
        self::assertSame($expected, $this->parser->isTransfer($line));
    }

    public function testParticipantBeforeTheVerbSends(): void
    {
        $transfer = $this->parse('boris sent 5000', $this->alice);

        self::assertSame($this->boris->id, $transfer->from->id);
        self::assertSame($this->alice->id, $transfer->to?->id);
        self::assertSame('5000 AMD', $transfer->amount->toDecimal() . ' ' . $transfer->amount->currency->code);
    }

    public function testToNamesTheRecipient(): void
    {
        $transfer = $this->parse('sent to boris 3000 rubles', $this->alice);

        self::assertSame($this->alice->id, $transfer->from->id);
        self::assertSame($this->boris->id, $transfer->to?->id);
        self::assertSame('RUB', $transfer->amount->currency->code);
        self::assertNull($transfer->note);
    }

    public function testMeMeansTheSpeaker(): void
    {
        $transfer = $this->parse('alice sent me 4000', $this->boris);

        self::assertSame($this->alice->id, $transfer->from->id);
        self::assertSame($this->boris->id, $transfer->to?->id);
    }

    public function testFromNamesTheSender(): void
    {
        $transfer = $this->parse('repaid from boris 1500', $this->alice);

        self::assertSame($this->boris->id, $transfer->from->id);
        self::assertSame($this->alice->id, $transfer->to?->id);
    }

    public function testSomeoneOutsideTheLedger(): void
    {
        $transfer = $this->parse('I sent to mom 10000 05.11 for the birthday', $this->alice);

        self::assertNull($transfer->to);
        self::assertSame('mom', $transfer->outsider);
        self::assertSame('2026-11-05', $transfer->date->format('Y-m-d'));
        self::assertSame('for the birthday', $transfer->note);

        $transfer = $this->parse('transferred mom 2000 dollars', $this->alice);
        self::assertSame('mom', $transfer->outsider);
        self::assertSame('USD', $transfer->amount->currency->code);
    }

    public function testRussianStillWorks(): void
    {
        $transfer = $this->parse('перевела для мамы 3000 руб', $this->alice);

        self::assertSame('мамы', $transfer->outsider);

        $transfer = $this->parse('скинула для boris 3000', $this->alice);
        self::assertSame($this->boris->id, $transfer->to?->id);
    }

    private function parse(string $line, Participant $speaker): TransferIntent
    {
        return $this->parser->parse(
            $line,
            new DateTimeImmutable(self::TODAY),
            $speaker,
            [$this->alice, $this->boris],
        );
    }
}
