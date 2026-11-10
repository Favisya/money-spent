<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Rates\PendingRateResolver;
use App\Bot\Telegram\Update;
use App\Bot\UpdateProcessor;
use App\Tests\Support\RecordingTelegramClient;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Runs bot scenarios end to end against a real PostgreSQL database (DATABASE_URL, "_test" suffix).
 */
final class BotFlowTest extends KernelTestCase
{
    private const int ANNA    = 1001;
    private const int BORIS   = 1002;
    private const array NAMES = [self::ANNA => 'Анна', self::BORIS => 'Борис'];

    private KernelInterface $app;
    private Connection $db;
    private UpdateProcessor $processor;
    private RecordingTelegramClient $telegram;
    private int $updateId = 1;

    protected function setUp(): void
    {
        $this->app       = self::bootKernel();
        $container       = self::getContainer();
        $telegram        = $container->get(RecordingTelegramClient::class);
        $this->db        = $container->get(Connection::class);
        $this->processor = $container->get(UpdateProcessor::class);
        $this->telegram  = $telegram instanceof RecordingTelegramClient
            ? $telegram
            : throw new \LogicException('Test Telegram client is not configured.');

        $this->db->executeStatement(
            'TRUNCATE telegram_update, settlement, expense, exchange_rate, participant, category '
            . 'RESTART IDENTITY CASCADE',
        );
        (new CommandTester((new Application($this->app))->find('app:seed')))->execute([]);

        $this->say(self::ANNA, '/start');
        $this->say(self::BORIS, '/start');
        $this->telegram->calls = [];
    }

    public function testForeignExpenseUsesNearestPreviousRateAndIsStoredOnce(): void
    {
        $this->rate('2026-11-09', 'EUR', '403.36');

        $update = $this->message(self::ANNA, '18,5 eur чатгпт', 500);
        $this->processor->process($update);
        $this->processor->process($update);
        $this->processor->process($this->message(self::ANNA, '18,5 eur чатгпт', 500));

        self::assertCount(2, $this->telegram->calls, 'Retried update is skipped, re-sent message re-shows the card.');
        self::assertStringContainsString('18.50 EUR · Подписки', $this->telegram->lastText());
        self::assertStringContainsString("× 403.36 = 7\u{00A0}462 AMD", $this->telegram->lastText());
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM expense'));
        self::assertSame(
            ['amount_minor' => 1850, 'rate_to_base' => '403.36000000', 'amount_base_minor' => 7462],
            $this->db->fetchAssociative('SELECT amount_minor, rate_to_base, amount_base_minor FROM expense'),
        );
    }

    public function testBalanceIsZeroAfterSettlingTheReportedDebt(): void
    {
        $this->say(self::ANNA, '17000 продукты');
        $this->say(self::BORIS, '9700 доставка вчера');
        $this->say(self::BORIS, '/balance');

        self::assertSame("💸 Борис должен(на) Анна 3\u{00A0}650 AMD", $this->telegram->lastText());

        $this->say(self::BORIS, '/settle 3650');
        self::assertStringContainsString('Никто никому не должен', $this->telegram->lastText());

        $this->say(self::BORIS, '/undo');
        self::assertStringContainsString('Удалил перевод', $this->telegram->lastText());

        $this->say(self::ANNA, '/balance');
        self::assertSame("💸 Борис должен(на) Анна 3\u{00A0}650 AMD", $this->telegram->lastText());
    }

    public function testSettlementInForeignCurrencyIsConverted(): void
    {
        $this->rate('2026-11-09', 'RUB', '4.3787');
        $this->say(self::ANNA, '17000 продукты');
        $this->say(self::BORIS, '/settle 1000 руб');

        self::assertStringContainsString("1\u{00A0}000.00 RUB ≈ 4\u{00A0}379 AMD", $this->telegram->lastText());
        self::assertStringContainsString("Борис должен(на) Анна 4\u{00A0}121 AMD", $this->telegram->lastText());
    }

    public function testMonthTotalsAllCurrenciesInBase(): void
    {
        $this->rate('2026-11-09', 'EUR', '403.36');
        $this->rate('2026-11-09', 'RUB', '4.3787');
        $this->say(self::ANNA, '17000 продукты');
        $this->say(self::ANNA, '18,5 eur чатгпт');
        $this->say(self::BORIS, '2500 руб бензин');
        $this->say(self::ANNA, '500 кофе лично 31.10');
        $this->say(self::ANNA, '/month');

        $text = $this->telegram->lastText();
        self::assertStringContainsString("Всего: 35\u{00A0}409 AMD", $text);
        self::assertStringContainsString("Продукты — 17\u{00A0}000 AMD (1)", $text);
        self::assertStringContainsString("Бензин — 10\u{00A0}947 AMD (1)", $text);
        self::assertStringContainsString("Анна: заплатил(а) 24\u{00A0}462 AMD, доля 17\u{00A0}704 AMD", $text);
        self::assertStringContainsString("Борис: заплатил(а) 10\u{00A0}947 AMD, доля 17\u{00A0}705 AMD", $text);

        $this->say(self::ANNA, '/month 10');
        self::assertStringContainsString("Всего: 500 AMD", $this->telegram->lastText());
    }

    public function testMissingRateIsReportedAndResolvedLater(): void
    {
        $this->say(self::ANNA, '1000 руб');
        self::assertStringContainsString('Курса RUB на 10.11.2026 пока нет', $this->telegram->lastText());

        $this->say(self::ANNA, '/balance');
        self::assertStringContainsString('Без курса и пока не учтено записей: 1', $this->telegram->lastText());

        $this->rate('2026-11-09', 'RUB', '4.3787');
        self::assertSame(1, self::getContainer()->get(PendingRateResolver::class)->resolve());
        self::assertSame(4379, (int) $this->db->fetchOne('SELECT amount_base_minor FROM expense'));
    }

    public function testStrangersAreIgnoredSilently(): void
    {
        $this->say(5555, '1000 продукты');

        self::assertSame([], $this->telegram->calls);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM expense'));
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM participant'));
    }

    public function testOnlyThePayerCanDeleteAnExpense(): void
    {
        $this->say(self::ANNA, '17000 продукты');
        $expenseId = (int) $this->db->fetchOne('SELECT id FROM expense');

        $this->press(self::BORIS, 'del:' . $expenseId);
        self::assertSame('Менять можно только свои траты.', $this->telegram->lastText());
        self::assertNull($this->db->fetchOne('SELECT deleted_at FROM expense'));

        $this->press(self::ANNA, 'del:' . $expenseId);
        self::assertNotNull($this->db->fetchOne('SELECT deleted_at FROM expense'));
        self::assertStringContainsString('Удалено', $this->telegram->calls[count($this->telegram->calls) - 2]['text']);

        $this->press(self::ANNA, 'setcat:' . $expenseId . ':1');
        self::assertSame('Трата не найдена или уже удалена.', $this->telegram->lastText());

        $this->press(self::ANNA, 'del:abc');
        self::assertSame('Кнопка устарела.', $this->telegram->lastText());
    }

    public function testAmbiguousCategoryOffersChoice(): void
    {
        $this->say(self::ANNA, '12000 продукты доставка');

        $keyboard = $this->telegram->last()['keyboard'];
        self::assertNotNull($keyboard);
        self::assertSame(['❓ Продукты', '❓ Доставка'], array_map(
            static fn ($button): string => $button->text,
            $keyboard->rows[0],
        ));
        self::assertStringContainsString('Прочее', $this->telegram->lastText());

        $this->press(self::ANNA, $keyboard->rows[0][1]->callbackData);
        self::assertSame('Категория: Доставка', $this->telegram->lastText());
        self::assertSame('delivery', $this->db->fetchOne(
            'SELECT c.code FROM expense e JOIN category c ON c.id = e.category_id',
        ));
    }

    public function testExportNeutralizesFormulas(): void
    {
        $this->say(self::ANNA, '100 =HYPERLINK("x")');
        $this->say(self::ANNA, '/export');

        $call = $this->telegram->last();
        self::assertSame('sendDocument', $call['method']);
        self::assertStringStartsWith("expenses-2026-11.csv\n\u{FEFF}id;date;payer", $call['text']);
        self::assertStringContainsString(';"\'=HYPERLINK(""x"")"', $call['text']);
    }

    public function testParseErrorExplainsWithExamples(): void
    {
        $this->say(self::ANNA, 'привет');

        self::assertStringContainsString('Не нашёл сумму', $this->telegram->lastText());
        self::assertStringContainsString('17000 продукты', $this->telegram->lastText());
    }

    public function testUnknownCommand(): void
    {
        $this->say(self::ANNA, '/nope');

        self::assertStringContainsString('Не знаю такую команду', $this->telegram->lastText());
    }

    public function testWebhookChecksSecret(): void
    {
        $body = json_encode(['update_id' => 999, 'message' => $this->messagePayload(self::ANNA, '100', 999)]);

        self::assertSame(403, $this->webhook((string) $body, 'wrong')->getStatusCode());
        self::assertSame(400, $this->webhook('{broken', 'test-secret')->getStatusCode());
        self::assertSame(200, $this->webhook((string) $body, 'test-secret')->getStatusCode());
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM expense'));
    }

    private function webhook(string $body, string $secret): Response
    {
        $request = Request::create(
            '/telegram/webhook',
            'POST',
            server: ['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => $secret, 'CONTENT_TYPE' => 'application/json'],
            content: $body,
        );

        return $this->app->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
    }

    private function say(int $userId, string $text): void
    {
        $this->processor->process($this->message($userId, $text, $this->updateId + 10_000));
    }

    private function press(int $userId, string $data): void
    {
        $this->processor->process(Update::fromArray([
            'update_id'      => $this->updateId++,
            'callback_query' => [
                'id'      => 'cb' . $this->updateId,
                'from'    => ['id' => $userId, 'is_bot' => false, 'first_name' => self::NAMES[$userId] ?? 'X'],
                'data'    => $data,
                'message' => ['message_id' => 1, 'chat' => ['id' => $userId, 'type' => 'private']],
            ],
        ]));
    }

    private function message(int $userId, string $text, int $messageId): Update
    {
        return Update::fromArray([
            'update_id' => $this->updateId++,
            'message'   => $this->messagePayload($userId, $text, $messageId),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function messagePayload(int $userId, string $text, int $messageId): array
    {
        return [
            'message_id' => $messageId,
            'chat'       => ['id' => $userId, 'type' => 'private'],
            'from'       => ['id' => $userId, 'is_bot' => false, 'first_name' => self::NAMES[$userId] ?? 'X'],
            'text'       => $text,
        ];
    }

    private function rate(string $date, string $currency, string $rate): void
    {
        $this->db->insert('exchange_rate', [
            'rate_date'    => $date,
            'currency'     => $currency,
            'rate_to_base' => $rate,
            'source'       => 'test',
        ]);
    }
}
