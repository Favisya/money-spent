<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Infrastructure\Persistence\ParticipantRepository;
use App\Tests\Support\FakeReceiptReader;
use App\Tests\Support\WebRequests;
use App\Tests\Unit\Infrastructure\Ai\ReceiptReadersTest;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Receipts end to end against PostgreSQL, with a fake model in place of Claude; "today" is 2026-11-10.
 */
final class PurchaseFlowTest extends KernelTestCase
{
    use WebRequests;

    private const string KEY = 'sk-ant-api03-test-key-1234';

    private KernelInterface $app;
    private Connection $db;
    private FakeReceiptReader $reader;
    private int $anna;
    private int $boris;

    protected function setUp(): void
    {
        $this->app = self::bootKernel();
        $this->db  = self::getContainer()->get(Connection::class);
        $reader    = self::getContainer()->get(FakeReceiptReader::class);
        self::assertInstanceOf(FakeReceiptReader::class, $reader);
        $this->reader = $reader;

        $this->db->executeStatement(
            'TRUNCATE app_setting, receipt, expense, settlement, exchange_rate, participant, category '
            . 'RESTART IDENTITY CASCADE',
        );
        (new CommandTester((new Application($this->app))->find('app:seed')))->execute([]);

        $participants = self::getContainer()->get(ParticipantRepository::class);
        self::assertInstanceOf(ParticipantRepository::class, $participants);
        $this->anna  = $participants->create('Анна', null)->id;
        $this->boris = $participants->create('Борис', null)->id;
    }

    public function testWithoutKeyTheReaderIsNotCalled(): void
    {
        $page = (string) $this->get('/purchases')->getContent();
        self::assertStringContainsString('выбери в настройках ИИ Claude Code или Codex', $page);

        $response = $this->post('/purchases/recognize', ['text' => 'хлеб 450']);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Сначала добавь API-ключ', (string) $response->getContent());
        self::assertNull($this->reader->lastInput);
    }

    public function testKeyIsStoredEncryptedAndOnlyItsEndIsShown(): void
    {
        $this->configure();

        $stored = (string) $this->db->fetchOne("SELECT value FROM app_setting WHERE name = 'ai.claude.key'");
        self::assertStringNotContainsString(self::KEY, $stored);
        self::assertStringNotContainsString(self::KEY, (string) base64_decode($stored, true));

        $page = (string) $this->get('/settings/ai')->getContent();
        self::assertStringContainsString('…1234', $page);
        self::assertStringNotContainsString(self::KEY, $page);

        $bad = $this->post('/settings/ai', ['provider' => 'claude', 'key' => ['claude' => 'short'], 'model' => []]);
        self::assertSame(422, $bad->getStatusCode());

        $this->post('/settings/ai', [
            'provider' => 'openai',
            'forget'   => ['claude' => '1'],
            'model'    => ['openai' => ''],
        ]);
        self::assertSame('openai', $this->db->fetchOne("SELECT value FROM app_setting WHERE name = 'ai.provider'"));
        self::assertFalse($this->db->fetchOne("SELECT value FROM app_setting WHERE name = 'ai.claude.key'"));
    }

    public function testSubscriptionToolNeedsNoKey(): void
    {
        $saved = $this->post('/settings/ai', ['provider' => 'claude-code', 'model' => ['claude-code' => '']]);
        self::assertSame(302, $saved->getStatusCode());

        $page = (string) $this->get('/purchases')->getContent();
        self::assertStringNotContainsString('Чтобы ИИ читал чеки', $page);
        self::assertStringContainsString('Читает Claude Code, модель по умолчанию.', $page);

        $settings = (string) $this->get('/settings/ai')->getContent();
        self::assertStringContainsString('Ключ не нужен', $settings);
        self::assertStringNotContainsString('name="key[claude-code]"', $settings);
    }

    public function testSettingsAreReachableFromTheHostMacOnly(): void
    {
        $remote = $this->get('/settings/ai', ['REMOTE_ADDR' => '192.168.1.20']);

        self::assertSame(302, $remote->getStatusCode());
        self::assertSame('/pair', $remote->headers->get('Location'));
    }

    public function testTypedListBecomesAReviewedReceiptAndOneGroceryExpense(): void
    {
        $this->configure();

        $recognized = $this->post('/purchases/recognize', ['text' => "молоко 2 шт 1200\nяблоки 1,5 кг"]);
        self::assertSame(302, $recognized->getStatusCode());
        self::assertSame('/purchases/1?ok=recognized', $recognized->headers->get('Location'));
        self::assertSame("молоко 2 шт 1200\nяблоки 1,5 кг", $this->reader->lastInput?->text);
        self::assertNotNull($this->reader->lastCredentials);
        self::assertSame(self::KEY, $this->reader->lastCredentials->apiKey);
        self::assertSame('claude-opus-5-5', $this->reader->lastCredentials->model);

        $draft = (string) $this->get('/purchases/1?ok=recognized')->getContent();
        self::assertStringContainsString('value="Яблоки"', $draft);
        self::assertStringContainsString('value="1350"', $draft);
        self::assertStringContainsString('Черновик', $draft);
        self::assertStringContainsString('Итого расходится с суммой по чеку', $draft);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM expense'));

        $saved = $this->post('/purchases/1', $this->form($this->anna, [
            ['name' => 'Молоко', 'quantity' => '2', 'unit' => 'pcs', 'price' => '600', 'amount' => ''],
            ['name' => 'Яблоки', 'quantity' => '1,5', 'unit' => 'kg', 'price' => '900', 'amount' => ''],
            ['name' => 'Хлеб', 'quantity' => '1', 'unit' => 'pcs', 'price' => '', 'amount' => '1400'],
            ['name' => 'Скидка', 'quantity' => '', 'unit' => 'pcs', 'price' => '', 'amount' => '-100'],
            ['name' => '', 'quantity' => '', 'unit' => 'pcs', 'price' => '', 'amount' => ''],
        ]));
        self::assertSame('/purchases/1?ok=saved', $saved->headers->get('Location'));

        self::assertSame(
            [
                'amount_minor' => 3850,
                'currency'     => 'AMD',
                'spent_at'     => '2026-11-09',
                'payer_id'     => $this->anna,
                'code'         => 'groceries',
                'note'         => 'SAS',
                'receipt_id'   => 1,
            ],
            $this->db->fetchAssociative(
                'SELECT e.amount_minor, e.currency, e.spent_at, e.payer_id, c.code, e.note, e.receipt_id '
                . 'FROM expense e JOIN category c ON c.id = e.category_id WHERE e.deleted_at IS NULL',
            ),
        );
        self::assertSame(
            ['Молоко', '2.000', 'pcs', 600, 1200],
            array_values((array) $this->db->fetchAssociative(
                'SELECT name, quantity, unit, unit_price_minor, amount_minor FROM receipt_item WHERE position = 1',
            )),
        );
        self::assertStringContainsString('Чек сохранён', (string) $this->get('/purchases/1?ok=saved')->getContent());
        self::assertStringContainsString('href="/purchases/1"', (string) $this->get('/?m=2026-11')->getContent());
    }

    public function testEditingTheReceiptRecordsTheExpenseAgain(): void
    {
        $this->savedReceipt($this->anna);

        $this->post('/purchases/1', $this->form((string) $this->boris, [
            ['name' => 'Молоко', 'quantity' => '2', 'unit' => 'pcs', 'price' => '600', 'amount' => '', 'remove' => '1'],
            ['name' => 'Хлеб', 'quantity' => '1', 'unit' => 'pcs', 'price' => '', 'amount' => '1400'],
        ]));

        self::assertSame(
            [['amount_minor' => 1400, 'payer_id' => $this->boris]],
            $this->db->fetchAllAssociative(
                'SELECT amount_minor, payer_id FROM expense WHERE deleted_at IS NULL AND receipt_id = 1',
            ),
        );
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM receipt_item'));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM expense WHERE deleted_at IS NOT NULL'));
    }

    public function testDeliveryIsPaidOnTopOfTheItems(): void
    {
        $this->savedReceipt($this->anna);

        $form = $this->form($this->anna, [
            ['name' => 'Хлеб', 'quantity' => '1', 'unit' => 'pcs', 'price' => '', 'amount' => '1400'],
        ]);

        $bad = $this->post('/purchases/1', [...$form, 'delivery' => '-300']);
        self::assertSame(422, $bad->getStatusCode());
        self::assertStringContainsString('Доставка — число не меньше нуля', (string) $bad->getContent());

        $this->post('/purchases/1', [...$form, 'delivery' => '500']);

        self::assertSame(1900, (int) $this->db->fetchOne(
            'SELECT amount_minor FROM expense WHERE deleted_at IS NULL AND receipt_id = 1',
        ));
        self::assertSame(500, (int) $this->db->fetchOne('SELECT delivery_minor FROM receipt WHERE id = 1'));

        $page = (string) $this->get('/purchases/1')->getContent();
        self::assertStringContainsString('value="500"', $page);
        self::assertMatchesRegularExpression('/Итого:\s*<strong class="num" data-total>1\D?900/u', $page);
        self::assertMatchesRegularExpression('/1\D?900/u', (string) $this->get('/purchases')->getContent());
    }

    public function testBothPayHalfOfTheReceipt(): void
    {
        $this->savedReceipt('both');

        self::assertSame(
            [1925, 1925],
            array_map('intval', $this->db->fetchFirstColumn(
                'SELECT amount_minor FROM expense WHERE deleted_at IS NULL AND receipt_id = 1 ORDER BY payer_id',
            )),
        );
    }

    public function testInvalidRowsAreNamed(): void
    {
        $this->savedReceipt($this->anna);

        $response = $this->post('/purchases/1', $this->form($this->anna, [
            ['name' => 'Молоко', 'quantity' => '2', 'unit' => 'pcs', 'price' => '600', 'amount' => ''],
            ['name' => 'Сыр', 'quantity' => '0,3', 'unit' => 'kg', 'price' => '', 'amount' => ''],
        ]));

        self::assertSame(422, $response->getStatusCode());
        $page = (string) $response->getContent();
        self::assertStringContainsString('Строка 2: укажи цену или сумму.', $page);
        self::assertStringContainsString('value="Сыр"', $page);
        self::assertSame(3850, (int) $this->db->fetchOne('SELECT amount_minor FROM expense WHERE deleted_at IS NULL'));

        $pieces = $this->post('/purchases/1', $this->form($this->anna, [
            ['name' => 'Яйца', 'quantity' => '1,5', 'unit' => 'pcs', 'price' => '100', 'amount' => ''],
        ]));
        self::assertStringContainsString('Строка 1: количество', (string) $pieces->getContent());
    }

    public function testDeletingTheReceiptDeletesItsExpense(): void
    {
        $this->savedReceipt($this->anna);

        $deleted = $this->post('/purchases/1/delete', []);
        self::assertSame('/purchases?ok=deleted', $deleted->headers->get('Location'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM expense WHERE deleted_at IS NULL'));
        self::assertSame(404, $this->get('/purchases/1')->getStatusCode());
    }

    public function testPhotoIsUploadedReadAndServedBack(): void
    {
        $this->configure();
        $png  = (string) base64_decode(ReceiptReadersTest::PNG, true);
        $file = $this->file($png, 'receipt.png');

        $response = $this->upload('/purchases/recognize', ['text' => ''], ['photo' => $file]);
        self::assertSame('/purchases/1?ok=recognized', $response->headers->get('Location'));
        self::assertSame('image/png', $this->reader->lastInput?->image?->mediaType);

        $image = $this->get('/purchases/1/image');
        self::assertSame($png, $image->getContent());
        self::assertSame('image/png', $image->headers->get('Content-Type'));
        self::assertSame('nosniff', $image->headers->get('X-Content-Type-Options'));

        $list = (string) $this->get('/purchases')->getContent();
        self::assertStringContainsString('черновик', $list);
        self::assertStringContainsString('фото', $list);

        $fake = $this->upload('/purchases/recognize', [], ['photo' => $this->file('<?php echo 1;', 'receipt.jpg')]);
        self::assertSame(422, $fake->getStatusCode());
        self::assertStringContainsString('Нужна картинка JPEG, PNG, WebP или GIF.', (string) $fake->getContent());
    }

    public function testManualReceiptWithoutAi(): void
    {
        $blank = $this->post('/purchases/blank', []);
        self::assertSame('/purchases/1?ok=blank', $blank->headers->get('Location'));

        $this->post('/purchases/1', [
            ...$this->form($this->anna, [
                ['name' => 'Арбуз', 'quantity' => '7,4', 'unit' => 'kg', 'price' => '250', 'amount' => ''],
            ]),
            'store' => '',
            'date'  => '2026-11-10',
        ]);

        self::assertSame(1850, (int) $this->db->fetchOne('SELECT amount_minor FROM expense WHERE deleted_at IS NULL'));
        self::assertNull($this->reader->lastInput);
    }

    public function testReceiptPagesAreInEnglish(): void
    {
        $this->savedReceipt($this->anna);

        foreach (['/purchases', '/purchases/1', '/settings/ai'] as $uri) {
            $page = (string) $this->get($uri, [], ['lang' => 'en'])->getContent();

            $typed = ['Молоко', 'Яблоки', 'Хлеб', 'Скидка', 'молоко', 'яблоки', 'хлеб'];

            self::assertSame([], array_values(array_diff($this->russianLeftovers($page), $typed)), $uri);
        }
    }

    private function configure(): void
    {
        $this->post('/settings/ai', [
            'provider' => 'claude',
            'key'      => ['claude' => self::KEY],
            'model'    => ['claude' => ''],
        ]);
    }

    private function savedReceipt(int|string $payer): void
    {
        $this->configure();
        $this->post('/purchases/recognize', ['text' => 'молоко, яблоки, хлеб']);
        $this->post('/purchases/1', $this->form((string) $payer, [
            ['name' => 'Молоко', 'quantity' => '2', 'unit' => 'pcs', 'price' => '600', 'amount' => ''],
            ['name' => 'Яблоки', 'quantity' => '1,5', 'unit' => 'kg', 'price' => '900', 'amount' => ''],
            ['name' => 'Хлеб', 'quantity' => '1', 'unit' => 'pcs', 'price' => '', 'amount' => '1400'],
            ['name' => 'Скидка', 'quantity' => '', 'unit' => 'pcs', 'price' => '', 'amount' => '-100'],
        ]));
    }

    /**
     * @param list<array<string, string>> $items
     *
     * @return array<string, mixed>
     */
    private function form(int|string $payer, array $items): array
    {
        return [
            'store'         => 'SAS',
            'date'          => '2026-11-09',
            'currency'      => 'AMD',
            'printed_total' => '3850',
            'payer'         => (string) $payer,
            'split'         => 'equal',
            'items'         => $items,
        ];
    }

    private function file(string $bytes, string $name): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'receipt');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, UPLOAD_ERR_OK, true);
    }
}
