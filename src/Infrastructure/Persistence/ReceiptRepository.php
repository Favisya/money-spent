<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Purchase\AiProvider;
use App\Application\Purchase\ReceiptContent;
use App\Application\Purchase\ReceiptDraft;
use App\Application\Purchase\ReceiptForm;
use App\Application\Purchase\ReceiptSource;
use App\Application\Purchase\ReceiptStatus;
use App\Application\Purchase\ReceiptSummary;
use App\Application\Purchase\ReceiptView;
use App\Application\Reference\CurrencyCatalog;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Purchase\Basket;
use App\Domain\Purchase\Quantity;
use App\Domain\Purchase\ReceiptLine;
use App\Domain\Purchase\Unit;
use App\Domain\Split\SplitType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use UnexpectedValueException;

final readonly class ReceiptRepository
{
    public function __construct(
        private Connection $connection,
        private CurrencyCatalog $currencies,
        private ExpenseRepository $expenses,
    ) {
    }

    /**
     * Raw SQL: Query Builder has no RETURNING.
     */
    public function create(ReceiptDraft $draft, string $uuid): int
    {
        return $this->connection->transactional(function () use ($draft, $uuid): int {
            $content = $draft->content;
            $id      = $this->connection->fetchOne(
                <<<'SQL'
                    INSERT INTO receipt (
                        uuid, status, source, provider, store, purchased_at, currency, printed_total_minor,
                        delivery_minor, source_text, image, image_type
                    )
                    VALUES (
                        :uuid, :status, :source, :provider, :store, :purchased_at, :currency, :printed_total,
                        :delivery, :source_text, :image, :image_type
                    )
                    RETURNING id
                    SQL,
                [
                    'uuid'          => $uuid,
                    'status'        => ReceiptStatus::Draft->value,
                    'source'        => $draft->source->value,
                    'provider'      => $draft->provider?->value,
                    'store'         => $content->store,
                    'purchased_at'  => $content->purchasedAt->format('Y-m-d'),
                    'currency'      => $content->basket->currency->code,
                    'printed_total' => $content->printedTotal?->minor,
                    'delivery'      => $content->delivery?->minor,
                    'source_text'   => $draft->sourceText,
                    'image'         => $draft->image?->bytes,
                    'image_type'    => $draft->image?->mediaType,
                ],
                ['image' => ParameterType::LARGE_OBJECT],
            );

            $receiptId = Row::int(['id' => $id], 'id');
            $this->insertLines($receiptId, $content->basket);

            return $receiptId;
        });
    }

    public function find(int $id): ?ReceiptView
    {
        $row = $this->connection->createQueryBuilder()
            ->select(
                'id',
                'uuid',
                'status',
                'source',
                'provider',
                'store',
                'purchased_at',
                'currency',
                'printed_total_minor',
                'delivery_minor',
                'payer_id',
                'paid_by_both',
                'split',
                'source_text',
                'image_type',
                'created_at',
            )
            ->from('receipt')
            ->where('id = :id')
            ->andWhere('deleted_at IS NULL')
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $currency = $this->currencies->get(Row::string($row, 'currency'));
        $printed  = Row::nullableInt($row, 'printed_total_minor');
        $delivery = Row::nullableInt($row, 'delivery_minor');

        return new ReceiptView(
            $id,
            Row::string($row, 'uuid'),
            ReceiptStatus::from(Row::string($row, 'status')),
            ReceiptSource::from(Row::string($row, 'source')),
            AiProvider::tryFrom((string) Row::nullableString($row, 'provider')),
            new ReceiptContent(
                Row::nullableString($row, 'store'),
                Row::date($row, 'purchased_at'),
                new Basket($currency, $this->lines($id, $currency)),
                $printed === null ? null : Money::ofMinor($printed, $currency),
                $delivery === null ? null : Money::ofMinor($delivery, $currency),
            ),
            Row::nullableInt($row, 'payer_id'),
            (bool) $row['paid_by_both'],
            SplitType::from(Row::string($row, 'split')),
            Row::nullableString($row, 'source_text'),
            Row::nullableString($row, 'image_type'),
            Row::date($row, 'created_at'),
            $this->expenses->idsByReceipt($id),
        );
    }

    /**
     * @return array{bytes: string, type: string}|null
     */
    public function image(int $id): ?array
    {
        $row = $this->connection->createQueryBuilder()
            ->select('image', 'image_type')
            ->from('receipt')
            ->where('id = :id')
            ->andWhere('deleted_at IS NULL')
            ->andWhere('image IS NOT NULL')
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $image = $row['image'];
        $bytes = is_resource($image) ? stream_get_contents($image) : $image;

        if (!is_string($bytes)) {
            throw new UnexpectedValueException('Receipt image is unreadable.');
        }

        return ['bytes' => $bytes, 'type' => Row::string($row, 'image_type')];
    }

    public function save(int $id, ReceiptForm $form): void
    {
        $this->connection->transactional(function () use ($id, $form): void {
            $content = $form->content;

            $this->connection->createQueryBuilder()
                ->update('receipt')
                ->set('status', ':status')
                ->set('store', ':store')
                ->set('purchased_at', ':purchased_at')
                ->set('currency', ':currency')
                ->set('printed_total_minor', ':printed_total')
                ->set('delivery_minor', ':delivery')
                ->set('payer_id', ':payer_id')
                ->set('paid_by_both', ':both')
                ->set('split', ':split')
                ->set('updated_at', 'now()')
                ->where('id = :id')
                ->setParameter('status', ReceiptStatus::Saved->value)
                ->setParameter('store', $content->store)
                ->setParameter('purchased_at', $content->purchasedAt->format('Y-m-d'))
                ->setParameter('currency', $content->basket->currency->code)
                ->setParameter('printed_total', $content->printedTotal?->minor)
                ->setParameter('delivery', $content->delivery?->minor)
                ->setParameter('payer_id', $form->payerId)
                ->setParameter('both', $form->isPaidByBoth(), ParameterType::BOOLEAN)
                ->setParameter('split', $form->split->value)
                ->setParameter('id', $id, ParameterType::INTEGER)
                ->executeStatement();

            $this->connection->createQueryBuilder()
                ->delete('receipt_item')
                ->where('receipt_id = :id')
                ->setParameter('id', $id, ParameterType::INTEGER)
                ->executeStatement();

            $this->insertLines($id, $content->basket);
        });
    }

    public function softDelete(int $id): void
    {
        $this->connection->createQueryBuilder()
            ->update('receipt')
            ->set('deleted_at', 'now()')
            ->where('id = :id')
            ->andWhere('deleted_at IS NULL')
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeStatement();
    }

    /**
     * @return list<ReceiptSummary> newest purchases first
     */
    public function summaries(int $limit): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select(
                'r.id',
                'r.status',
                'r.store',
                'r.purchased_at',
                'r.currency',
                'r.paid_by_both',
                'p.name AS payer_name',
                '(r.image IS NOT NULL) AS has_image',
                'COALESCE((SELECT SUM(i.amount_minor) FROM receipt_item i WHERE i.receipt_id = r.id), 0)'
                . ' + COALESCE(r.delivery_minor, 0) AS total',
                '(SELECT COUNT(*) FROM receipt_item i WHERE i.receipt_id = r.id) AS item_count',
                'EXISTS (SELECT 1 FROM expense e WHERE e.receipt_id = r.id AND e.deleted_at IS NULL) AS has_expense',
            )
            ->from('receipt', 'r')
            ->leftJoin('r', 'participant', 'p', 'p.id = r.payer_id')
            ->where('r.deleted_at IS NULL')
            ->orderBy('r.purchased_at', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            fn (array $row): ReceiptSummary => new ReceiptSummary(
                Row::int($row, 'id'),
                ReceiptStatus::from(Row::string($row, 'status')),
                Row::nullableString($row, 'store'),
                Row::date($row, 'purchased_at'),
                Money::ofMinor(Row::int($row, 'total'), $this->currencies->get(Row::string($row, 'currency'))),
                Row::int($row, 'item_count'),
                Row::nullableString($row, 'payer_name'),
                (bool) $row['paid_by_both'],
                (bool) $row['has_expense'],
                (bool) $row['has_image'],
            ),
            $rows,
        );
    }

    private function insertLines(int $receiptId, Basket $basket): void
    {
        foreach ($basket->lines as $position => $line) {
            $this->connection->insert('receipt_item', [
                'receipt_id'       => $receiptId,
                'position'         => $position + 1,
                'name'             => $line->name,
                'quantity'         => $line->quantity->value,
                'unit'             => $line->quantity->unit->value,
                'unit_price_minor' => $line->unitPrice?->minor,
                'amount_minor'     => $line->amount->minor,
            ]);
        }
    }

    /**
     * @return list<ReceiptLine>
     */
    private function lines(int $receiptId, Currency $currency): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('name', 'quantity', 'unit', 'unit_price_minor', 'amount_minor')
            ->from('receipt_item')
            ->where('receipt_id = :id')
            ->orderBy('position')
            ->setParameter('id', $receiptId, ParameterType::INTEGER)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static function (array $row) use ($currency): ReceiptLine {
                $price = Row::nullableInt($row, 'unit_price_minor');

                return ReceiptLine::of(
                    Row::string($row, 'name'),
                    Quantity::of(Row::string($row, 'quantity'), Unit::from(Row::string($row, 'unit'))),
                    $price === null ? null : Money::ofMinor($price, $currency),
                    Money::ofMinor(Row::int($row, 'amount_minor'), $currency),
                );
            },
            $rows,
        );
    }
}
