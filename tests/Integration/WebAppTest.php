<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Infrastructure\Persistence\ParticipantRepository;
use App\Tests\Support\StaticRateSource;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The local web app end to end against PostgreSQL; "today" is 2026-11-10 in Yerevan (MockClock).
 */
final class WebAppTest extends KernelTestCase
{
    private const string ORIGIN = 'http://localhost';

    private KernelInterface $app;
    private Connection $db;
    private int $anna;
    private int $boris;

    protected function setUp(): void
    {
        $this->app = self::bootKernel();
        $this->db  = self::getContainer()->get(Connection::class);

        $this->db->executeStatement(
            'TRUNCATE telegram_update, settlement, expense, exchange_rate, participant, category '
            . 'RESTART IDENTITY CASCADE',
        );
        (new CommandTester((new Application($this->app))->find('app:seed')))->execute([]);
    }

    public function testFirstVisitAsksForParticipants(): void
    {
        $response = $this->get('/');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/participants', $response->headers->get('Location'));
        self::assertStringContainsString('Кто ведёт учёт?', (string) $this->get('/participants')->getContent());
    }

    public function testParticipantValidation(): void
    {
        self::assertSame(302, $this->post('/participants', ['name' => 'Анна', 'telegram_id' => ''])->getStatusCode());

        $invalid = $this->post('/participants', ['name' => 'Борис', 'telegram_id' => '12ab']);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringContainsString('Telegram id — это число', (string) $invalid->getContent());

        $this->post('/participants/1', ['name' => 'Аня', 'telegram_id' => '1001']);
        self::assertSame(
            ['name' => 'Аня', 'telegram_id' => 1001],
            $this->db->fetchAssociative('SELECT name, telegram_id FROM participant WHERE id = 1'),
        );
    }

    public function testAddEditDeleteExpense(): void
    {
        $this->people();
        $this->rate('2026-11-09', 'EUR', '403.36');

        $added = $this->post('/expenses', ['text' => '18,5 eur чатгпт <b>x</b>', 'payer' => $this->boris]);
        self::assertSame(302, $added->getStatusCode());
        self::assertSame('/?m=2026-11&ok=added&id=1', $added->headers->get('Location'));
        self::assertSame(7462, (int) $this->db->fetchOne('SELECT amount_base_minor FROM expense'));

        $page = (string) $this->get('/?m=2026-11&ok=added&id=1')->getContent();
        self::assertStringContainsString('Трата записана.', $page);
        self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $page);
        self::assertStringNotContainsString('<b>x</b>', $page);
        self::assertStringContainsString("Анна</strong>", $page);
        self::assertStringContainsString("3\u{00A0}731 AMD", $page);

        $delivery = (int) $this->db->fetchOne("SELECT id FROM category WHERE code = 'delivery'");
        $this->post('/expenses/1/category', ['category' => $delivery, 'back' => '2026-11']);
        self::assertSame($delivery, (int) $this->db->fetchOne('SELECT category_id FROM expense'));

        $this->post('/expenses/1/note', ['note' => '  подписка  на год ', 'back' => '2026-11']);
        self::assertSame('подписка на год', $this->db->fetchOne('SELECT note FROM expense WHERE id = 1'));
        $this->post('/expenses/1/note', ['note' => '', 'back' => '2026-11']);
        self::assertNull($this->db->fetchOne('SELECT note FROM expense WHERE id = 1'));

        $this->post('/expenses/1/delete', ['back' => '2026-11']);
        self::assertNotNull($this->db->fetchOne('SELECT deleted_at FROM expense'));
    }

    public function testInvalidExpenseIsExplained(): void
    {
        $this->people();

        $response = $this->post('/expenses', ['text' => 'привет', 'payer' => $this->anna]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Не нашёл сумму', (string) $response->getContent());
        self::assertStringContainsString('value="привет"', (string) $response->getContent());
    }

    public function testCrossSiteAndForeignHostRequestsAreRejected(): void
    {
        $this->people();

        self::assertSame(403, $this->post('/expenses', ['text' => '100', 'payer' => 1], [])->getStatusCode());
        self::assertSame(403, $this->post('/expenses', ['text' => '100', 'payer' => 1], [
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        ])->getStatusCode());
        self::assertSame(403, $this->post('/expenses', ['text' => '100', 'payer' => 1], [
            'HTTP_ORIGIN' => 'http://evil.example',
        ])->getStatusCode());
        self::assertSame(400, $this->get('/', ['HTTP_HOST' => 'evil.example'])->getStatusCode());
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM expense'));

        $headers = $this->get('/')->headers;
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
        self::assertStringContainsString("frame-ancestors 'none'", (string) $headers->get('Content-Security-Policy'));
    }

    public function testImportPreviewConfirmAndRepeat(): void
    {
        $this->people();
        $note = "Октябрь\n03.10\n21000 продукты\n25 eur netflix от бориса\n\nкривая строка 1.5\n"
            . "12.10\n18000 бензин\n31.02\n";

        $preview = (string) $this->post('/import', [
            'text'  => $note,
            'payer' => $this->anna,
            'date'  => '2026-11-10',
        ])->getContent();
        self::assertStringContainsString('Распознано трат: <span class="num">3</span>', $preview);
        self::assertStringContainsString('без суммы, пропускаю', $preview);
        self::assertStringContainsString('Не понял дату «31.02»', $preview);
        self::assertMatchesRegularExpression('/line_payer\[4\]".*?value="' . $this->boris . '" selected/s', $preview);

        $form   = ['text' => $note, 'payer' => $this->anna, 'date' => '2026-11-10', 'lines' => [3, 4, 8]];
        $payers = ['line_payer' => [3 => $this->anna, 4 => $this->boris, 8 => $this->boris]];
        $done   = (string) $this->post('/import/confirm', $form + $payers)->getContent();

        self::assertMatchesRegularExpression('/Записано трат: 3, пересчитано\s+по курсу ЦБ: 1/u', $done);
        self::assertSame(
            [
                ['spent_at' => '2026-10-03', 'payer_id' => $this->anna, 'amount_base_minor' => 21000],
                ['spent_at' => '2026-10-03', 'payer_id' => $this->boris, 'amount_base_minor' => 10000],
                ['spent_at' => '2026-10-12', 'payer_id' => $this->boris, 'amount_base_minor' => 18000],
            ],
            $this->db->fetchAllAssociative('SELECT spent_at, payer_id, amount_base_minor FROM expense ORDER BY id'),
        );

        $source = self::getContainer()->get(StaticRateSource::class);
        self::assertInstanceOf(StaticRateSource::class, $source);
        self::assertSame(['2026-10-03'], $source->requestedDates);

        $again = (string) $this->post('/import/confirm', $form)->getContent();
        self::assertMatchesRegularExpression('/Записано трат: 0, пропущено\s+уже импортированных: 3/u', $again);
        self::assertSame(3, (int) $this->db->fetchOne('SELECT COUNT(*) FROM expense'));
    }

    public function testReportsDownload(): void
    {
        $this->people();
        $this->post('/expenses', ['text' => '17000 продукты', 'payer' => $this->anna]);

        $pdf = $this->get('/report/2026-11.pdf');
        self::assertSame(200, $pdf->getStatusCode());
        self::assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF-', (string) $pdf->getContent());
        self::assertStringContainsString('otchet-2026-11.pdf', (string) $pdf->headers->get('Content-Disposition'));

        $csv = $this->get('/export/2026-11.csv');
        self::assertSame(200, $csv->getStatusCode());
        self::assertStringContainsString('Продукты;17000;AMD', (string) $csv->getContent());

        self::assertSame(404, $this->get('/report/2026-13.pdf')->getStatusCode());
    }

    public function testSettlementFromWeb(): void
    {
        $this->people();
        $this->post('/expenses', ['text' => '17000 продукты', 'payer' => $this->anna]);

        $settled = $this->post('/settlements', ['from' => $this->boris, 'amount' => '8500']);
        self::assertSame(302, $settled->getStatusCode());
        self::assertStringContainsString('Никто никому не должен', (string) $this->get('/')->getContent());
    }

    public function testWhoseExpenseIsChosenAndChanged(): void
    {
        $this->people();
        $this->rate('2026-11-09', 'USD', '387.5');

        $this->post('/expenses', ['text' => '10 usd icloud', 'payer' => $this->anna, 'whose' => 'payer_only']);
        $this->post('/expenses', ['text' => '700 кофе лично', 'payer' => $this->boris, 'whose' => 'equal']);
        $this->post('/expenses', ['text' => '3000 цветы', 'payer' => $this->anna, 'whose' => 'bogus']);

        self::assertSame(
            [
                ['split' => 'payer_only', 'amount_base_minor' => 3875],
                ['split' => 'payer_only', 'amount_base_minor' => 700],
                ['split' => 'equal', 'amount_base_minor' => 3000],
            ],
            $this->db->fetchAllAssociative('SELECT split, amount_base_minor FROM expense ORDER BY id'),
        );

        $changed = $this->post('/expenses/3/split', ['split' => 'other_only', 'back' => '2026-11', 'who' => 'shared']);
        self::assertSame('/?m=2026-11&who=shared&ok=split&id=3', $changed->headers->get('Location'));
        self::assertSame('other_only', $this->db->fetchOne('SELECT split FROM expense WHERE id = 3'));
        self::assertSame(302, $this->post('/expenses/3/split', ['split' => 'x'])->getStatusCode());
        self::assertSame('other_only', $this->db->fetchOne('SELECT split FROM expense WHERE id = 3'));

        $filtered = (string) $this->get('/?m=2026-11&who=p' . $this->boris)->getContent();
        self::assertMatchesRegularExpression('/Траты <span class="muted num">2<\/span>/', $filtered);
        self::assertStringContainsString('цветы', $filtered);
        self::assertStringNotContainsString('icloud', $filtered);
    }

    public function testAnalyticsPage(): void
    {
        $this->people();
        $this->post('/expenses', ['text' => '17000 продукты ашан', 'payer' => $this->anna]);
        $this->post('/expenses', ['text' => '700 кофе лично', 'payer' => $this->boris]);
        $this->post('/expenses', ['text' => '9700 доставка 05.10', 'payer' => $this->boris]);

        $response = $this->get('/analytics');
        $page     = (string) $response->getContent();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Куда уходят деньги', $page);
        self::assertStringContainsString('class="chord"', $page);
        self::assertStringContainsString('Борис лично', $page);
        self::assertMatchesRegularExpression('/Всего<\/p><p class="total num">27\s400\sAMD/u', $page);

        $personal = (string) $this->get('/analytics?n=1&who=p' . $this->boris)->getContent();
        self::assertMatchesRegularExpression('/Борис лично<\/p><p class="total num">700\sAMD/u', $personal);

        $empty = (string) $this->get('/analytics?from=2025-01&to=2025-02')->getContent();
        self::assertStringContainsString('За этот период нет трат', $empty);

        $reversed = (string) $this->get('/analytics?from=2026-11&to=2026-01')->getContent();
        self::assertStringContainsString('Начало периода позже конца', $reversed);
        self::assertStringContainsString('Такого периода нет', (string) $this->get('/analytics?n=5')->getContent());
        $allTime = (string) $this->get('/analytics?all=1')->getContent();
        self::assertMatchesRegularExpression('/Октябрь 2026\s+—\s+Ноябрь 2026/u', $allTime);
    }

    public function testCategoriesCanBeAddedEditedAndHidden(): void
    {
        $this->people();

        $added = $this->post('/categories', ['title' => ' Кафе ', 'keywords' => 'Ресторан, кофейня  бар']);
        self::assertSame(302, $added->getStatusCode());
        $cafe     = (int) $this->db->fetchOne("SELECT id FROM category WHERE title = 'Кафе'");
        $keywords = $this->db->fetchOne('SELECT keywords FROM category WHERE id = ?', [$cafe]);
        self::assertSame('["ресторан", "кофейня", "бар"]', $keywords);

        $this->post('/expenses', ['text' => '2500 ресторан', 'payer' => $this->anna]);
        self::assertSame($cafe, (int) $this->db->fetchOne('SELECT category_id FROM expense'));

        $duplicate = $this->post('/categories', ['title' => 'кафе', 'keywords' => '']);
        self::assertSame(422, $duplicate->getStatusCode());
        self::assertStringContainsString('уже есть', (string) $duplicate->getContent());

        $bad = $this->post('/categories/' . $cafe, ['title' => 'Кафе', 'keywords' => '<b>']);
        self::assertSame(422, $bad->getStatusCode());
        self::assertStringContainsString('&lt;b&gt;', (string) $bad->getContent());

        $this->post('/categories/' . $cafe, ['title' => 'Кафе и бары', 'keywords' => 'ресторан']);
        self::assertSame('Кафе и бары', $this->db->fetchOne('SELECT title FROM category WHERE id = ?', [$cafe]));

        $this->post('/categories/' . $cafe . '/visibility', ['active' => '0']);
        self::assertFalse((bool) $this->db->fetchOne('SELECT is_active FROM category WHERE id = ?', [$cafe]));
        self::assertStringContainsString('Кафе и бары (скрыта)', (string) $this->get('/?m=2026-11')->getContent());

        $this->post('/expenses', ['text' => '900 ресторан', 'payer' => $this->anna]);
        $latest = (int) $this->db->fetchOne('SELECT category_id FROM expense ORDER BY id DESC LIMIT 1');
        self::assertNotSame($cafe, $latest);

        $other = (int) $this->db->fetchOne("SELECT id FROM category WHERE code = 'other'");
        self::assertSame(422, $this->post('/categories/' . $other . '/visibility', ['active' => '0'])->getStatusCode());

        $this->post('/categories/' . $cafe . '/visibility', ['active' => '1']);
        self::assertTrue((bool) $this->db->fetchOne('SELECT is_active FROM category WHERE id = ?', [$cafe]));

        (new CommandTester((new Application($this->app))->find('app:seed')))->execute([]);
        $groceries = $this->db->fetchOne("SELECT is_active FROM category WHERE code = 'groceries'");
        self::assertTrue((bool) $groceries);
        self::assertSame('Кафе и бары', $this->db->fetchOne('SELECT title FROM category WHERE id = ?', [$cafe]));
    }

    public function testAmountsCanBeShownInAnotherCurrency(): void
    {
        $this->people();
        $this->rate('2026-11-09', 'USD', '387.5');
        $this->post('/expenses', ['text' => '17000 продукты', 'payer' => $this->anna]);

        $chosen = $this->post('/display-currency', ['code' => 'USD', 'back' => '/analytics?n=1']);
        self::assertSame('/analytics?n=1', $chosen->headers->get('Location'));
        $cookie = $chosen->headers->getCookies()[0] ?? null;
        self::assertSame(['display_currency', 'USD'], [$cookie?->getName(), $cookie?->getValue()]);

        $page = (string) $this->get('/?m=2026-11', [], ['display_currency' => 'USD'])->getContent();
        self::assertMatchesRegularExpression('/class="total num">43\.87 USD/', $page);
        self::assertStringContainsString("<span>17\u{00A0}000 AMD</span>", $page);
        self::assertStringContainsString('aria-pressed="true">USD', $page);
        self::assertStringContainsString('17 000 AMD', str_replace("\u{00A0}", ' ', $page));

        $noRate = (string) $this->get('/?m=2026-11', [], ['display_currency' => 'EUR'])->getContent();
        self::assertMatchesRegularExpression('/class="total num">17\s000 AMD/u', $noRate);

        self::assertSame('/', $this->post('/display-currency', ['code' => 'USD', 'back' => '//evil.example'])
            ->headers->get('Location'));
        self::assertSame([], $this->post('/display-currency', ['code' => 'XYZ', 'back' => '/'])->headers->getCookies());
    }

    public function testPhoneNoteWithSignAsPayer(): void
    {
        $this->people();
        $note = implode("\n", [
            '+9700 драмм 05.11.2026 доставка кари',
            '+17000 драмм 05.11.2026 продукты',
            '-1000 рублей на майн',
            '+ 5000 драмм 06.11.2026',
            '8000 драмм продукты 07.11.2026',
            '-21000 драмм доставка 08.11.2026',
            '-2500 рублей от алисы 09.11.2026',
            '+13930 драм комуналка 10.11.2026',
            '+18,5 евро чатгпт 10.11.2026',
            '+4540 драм газ 10.11.2026',
            '+11000 драм свет 10.11.2026',
        ]);
        $form = [
            'text'        => $note,
            'payer'       => $this->anna,
            'date'        => '2026-11-10',
            'sign_mode'   => 'payer',
            'plus_payer'  => $this->anna,
            'minus_payer' => $this->boris,
        ];

        $preview = (string) $this->post('/import', $form)->getContent();
        self::assertStringContainsString('Распознано трат: <span class="num">11</span>', $preview);

        $this->post('/import/confirm', $form + ['lines' => range(1, 11)]);

        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT e.spent_at, p.name AS payer, e.amount_minor, e.currency, c.code AS category, e.note
            FROM expense e
            JOIN participant p ON p.id = e.payer_id
            JOIN category c ON c.id = e.category_id
            ORDER BY e.id
            SQL);

        self::assertSame(
            [
                ['2026-11-05', 'Анна', 9700, 'AMD', 'delivery', 'доставка кари'],
                ['2026-11-05', 'Анна', 17000, 'AMD', 'groceries', null],
                ['2026-11-10', 'Борис', 100000, 'RUB', 'other', 'на майн'],
                ['2026-11-06', 'Анна', 5000, 'AMD', 'other', null],
                ['2026-11-07', 'Анна', 8000, 'AMD', 'groceries', null],
                ['2026-11-08', 'Борис', 21000, 'AMD', 'delivery', null],
                ['2026-11-09', 'Борис', 250000, 'RUB', 'other', 'от алисы'],
                ['2026-11-10', 'Анна', 13930, 'AMD', 'utilities', 'комуналка'],
                ['2026-11-10', 'Анна', 1850, 'EUR', 'subscriptions', 'чатгпт'],
                ['2026-11-10', 'Анна', 4540, 'AMD', 'gas', 'газ'],
                ['2026-11-10', 'Анна', 11000, 'AMD', 'utilities', 'свет'],
            ],
            array_map(array_values(...), $rows),
        );
    }

    public function testRecurringExpenses(): void
    {
        $this->people();
        $this->rate('2026-11-09', 'EUR', '403.36');

        $added = $this->post('/recurring', [
            'text'  => '18,5 eur чатгпт',
            'payer' => $this->anna,
            'day'   => 10,
            'whose' => 'equal',
        ]);
        self::assertSame(302, $added->getStatusCode());
        self::assertSame(
            [['spent_at' => '2026-11-10', 'amount_minor' => 1850, 'recurring_id' => 1]],
            $this->db->fetchAllAssociative('SELECT spent_at, amount_minor, recurring_id FROM expense'),
        );

        $this->post('/recurring', ['text' => '2990 руб кинопоиск', 'payer' => $this->boris, 'day' => 5]);
        self::assertSame('2026-12-01', $this->db->fetchOne('SELECT next_month FROM recurring_expense WHERE id = 2'));

        $this->post('/expenses', ['text' => '9700 доставка 05.09', 'payer' => $this->boris]);
        $expense = (int) $this->db->fetchOne("SELECT id FROM expense WHERE spent_at = '2026-09-05'");
        $this->post('/expenses/' . $expense . '/recurring', []);
        self::assertSame(
            ['2026-09-05', '2026-10-05', '2026-11-05'],
            $this->db->fetchFirstColumn('SELECT spent_at FROM expense WHERE recurring_id = 3 ORDER BY spent_at'),
        );
        self::assertSame(302, $this->post('/expenses/' . $expense . '/recurring', [])->getStatusCode());
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM recurring_expense WHERE id > 3'));

        $command = new CommandTester((new Application($this->app))->find('app:recurring:run'));
        $command->execute([]);
        self::assertStringContainsString('Booked recurring expenses: 0', $command->getDisplay());

        $this->post('/recurring/3/active', ['active' => '0']);
        self::assertFalse((bool) $this->db->fetchOne('SELECT is_active FROM recurring_expense WHERE id = 3'));
        $this->post('/recurring/3/active', ['active' => '1']);
        self::assertSame('2026-12-01', $this->db->fetchOne('SELECT next_month FROM recurring_expense WHERE id = 3'));

        $page = (string) $this->get('/recurring')->getContent();
        self::assertStringContainsString('05.12.2026', $page);
        self::assertStringContainsString('10.12.2026', $page);

        $this->post('/recurring/1/delete', []);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM recurring_expense WHERE id = 1'));
        self::assertNull($this->db->fetchOne("SELECT recurring_id FROM expense WHERE spent_at = '2026-11-10'"));
    }

    public function testTransfersFromTheEntryLine(): void
    {
        $this->people();
        $this->rate('2026-11-09', 'RUB', '4.5');

        $this->post('/expenses', ['text' => 'борис скинул 5000', 'payer' => $this->anna]);
        $this->post('/expenses', ['text' => 'скинула борису 3000 руб', 'payer' => $this->anna]);
        $this->post('/expenses', ['text' => 'анна скинула мне 4000', 'payer' => $this->boris]);
        $this->post('/expenses', ['text' => 'перевела 2000 за квартиру', 'payer' => $this->anna]);
        $this->post('/expenses', ['kind' => 'transfer', 'text' => '1500', 'payer' => $this->anna, 'to' => '']);

        self::assertSame(
            [
                [$this->boris, $this->anna, 5000, 'AMD', null],
                [$this->anna, $this->boris, 300000, 'RUB', null],
                [$this->anna, $this->boris, 4000, 'AMD', null],
                [$this->anna, $this->boris, 2000, 'AMD', 'за квартиру'],
                [$this->anna, $this->boris, 1500, 'AMD', null],
            ],
            array_map(
                array_values(...),
                $this->db->fetchAllAssociative(
                    'SELECT from_id, to_id, amount_minor, currency, note FROM settlement ORDER BY id',
                ),
            ),
        );
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM expense'));

        $sent = $this->post('/expenses', ['text' => 'скинула маме 10000', 'payer' => $this->anna]);
        self::assertStringContainsString('ok=sent', (string) $sent->headers->get('Location'));
        $this->post('/expenses', [
            'kind'    => 'transfer',
            'text'    => '7000 на день рождения',
            'payer'   => $this->boris,
            'to'      => 'other',
            'to_name' => 'Саша',
        ]);

        self::assertSame(
            [
                [$this->anna, 10000, 'payer_only', 'transfers', '→ маме'],
                [$this->boris, 7000, 'payer_only', 'transfers', '→ Саша: на день рождения'],
            ],
            array_map(array_values(...), $this->db->fetchAllAssociative(<<<'SQL'
                SELECT e.payer_id, e.amount_minor, e.split, c.code, e.note
                FROM expense e JOIN category c ON c.id = e.category_id
                ORDER BY e.id
                SQL)),
        );

        $self = $this->post('/expenses', [
            'kind'  => 'transfer',
            'text'  => '100',
            'payer' => $this->anna,
            'to'    => (string) $this->anna,
        ]);
        self::assertSame(422, $self->getStatusCode());
        self::assertStringContainsString('самому себе', (string) $self->getContent());
    }

    public function testTransfersInImportedNote(): void
    {
        $this->people();
        $note = "-5000 борис скинул 07.11\n+3000 скинула маме 08.11\n+17000 продукты 08.11";
        $form = [
            'text'        => $note,
            'payer'       => $this->anna,
            'date'        => '2026-11-10',
            'sign_mode'   => 'payer',
            'plus_payer'  => $this->anna,
            'minus_payer' => $this->boris,
        ];

        $preview = (string) $this->post('/import', $form)->getContent();
        self::assertMatchesRegularExpression(
            '/Распознано трат: <span class="num">1<\/span>, переводов:\s+<span class="num">2/u',
            $preview,
        );
        self::assertMatchesRegularExpression(
            '/Борис<\/span> → <span class="pname"><span class="avatar"[^>]*>А<\/span>Анна/u',
            $preview,
        );
        self::assertStringContainsString('Анна</span> → маме', $preview);

        $this->post('/import/confirm', $form + ['lines' => [1, 2, 3]]);
        self::assertSame(
            ['from_id' => $this->boris, 'to_id' => $this->anna, 'amount_minor' => 5000, 'settled_at' => '2026-11-07'],
            $this->db->fetchAssociative('SELECT from_id, to_id, amount_minor, settled_at FROM settlement'),
        );
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM expense'));

        $again = (string) $this->post('/import/confirm', $form + ['lines' => [1, 2, 3]])->getContent();
        self::assertMatchesRegularExpression('/Записано трат: 0, пропущено\s+уже импортированных: 3/u', $again);
    }

    public function testImportNotesCanBeEditedAndFilledLater(): void
    {
        $this->people();
        $form = [
            'text'  => "11000 драм свет 09.11\n17000 продукты 09.11\n4540 газ 09.11",
            'payer' => $this->anna,
            'date'  => '2026-11-10',
        ];

        $preview = (string) $this->post('/import', $form)->getContent();
        self::assertStringContainsString('name="line_note[1]" maxlength="255"', $preview);
        self::assertMatchesRegularExpression('/line_note\[1\][^>]*value="свет"/u', $preview);
        self::assertMatchesRegularExpression('/line_note\[2\][^>]*value=""/u', $preview);

        $this->post('/import/confirm', $form + [
            'lines'     => [1, 2, 3],
            'line_note' => [1 => '', 3 => ' газ  за октябрь '],
        ]);
        self::assertSame(
            [null, null, 'газ за октябрь'],
            $this->db->fetchFirstColumn('SELECT note FROM expense ORDER BY id'),
        );

        $again = (string) $this->post('/import/confirm', $form + ['lines' => [1, 2, 3]])->getContent();
        self::assertMatchesRegularExpression('/дописано заметок «на что»:\s+1/u', $again);
        self::assertSame(
            ['свет', null, 'газ за октябрь'],
            $this->db->fetchFirstColumn('SELECT note FROM expense ORDER BY id'),
        );
    }

    public function testParticipantColorsShowEverywhere(): void
    {
        $this->people();
        $this->post('/expenses', ['text' => '17000 продукты', 'payer' => $this->boris, 'whose' => 'payer_only']);

        $page = (string) $this->get('/?m=2026-11')->getContent();
        self::assertStringContainsString('style="--av: var(--pc-violet)" aria-hidden="true">Б</span>', $page);

        $saved = $this->post('/participants/' . $this->boris, [
            'name'        => 'Борис',
            'telegram_id' => '',
            'color'       => 'green',
        ]);
        self::assertSame(302, $saved->getStatusCode());
        self::assertSame('green', $this->db->fetchOne('SELECT color FROM participant WHERE id = ?', [$this->boris]));

        $recolored = (string) $this->get('/?m=2026-11')->getContent();
        self::assertStringContainsString('var(--pc-green)" aria-hidden="true">Б</span>', $recolored);
        self::assertStringContainsString('var(--pc-green)', (string) $this->get('/analytics?n=1')->getContent());
        $people = (string) $this->get('/participants')->getContent();
        self::assertMatchesRegularExpression('/value="green" checked/', $people);

        $bad = $this->post('/participants/' . $this->boris, [
            'name'        => 'Борис',
            'telegram_id' => '',
            'color'       => 'url(x)',
        ]);
        self::assertSame(422, $bad->getStatusCode());
        self::assertStringContainsString('Такого цвета нет', (string) $bad->getContent());
        self::assertSame('green', $this->db->fetchOne('SELECT color FROM participant WHERE id = ?', [$this->boris]));
    }

    private function people(): void
    {
        $participants = self::getContainer()->get(ParticipantRepository::class);
        self::assertInstanceOf(ParticipantRepository::class, $participants);
        $this->anna  = $participants->create('Анна', null)->id;
        $this->boris = $participants->create('Борис', null)->id;
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

    /**
     * @param array<string, string> $server
     * @param array<string, string> $cookies
     */
    private function get(string $uri, array $server = [], array $cookies = []): Response
    {
        $request = Request::create($uri, 'GET', cookies: $cookies, server: $server);

        return $this->app->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
    }

    /**
     * @param array<string, mixed>       $data
     * @param array<string, string>|null $server null means a same-origin browser post
     */
    private function post(string $uri, array $data, ?array $server = null): Response
    {
        $request = Request::create($uri, 'POST', $data, server: $server ?? ['HTTP_ORIGIN' => self::ORIGIN]);

        return $this->app->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
    }
}
