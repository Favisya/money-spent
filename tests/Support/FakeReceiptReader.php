<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Purchase\AiCredentials;
use App\Application\Purchase\AiProvider;
use App\Application\Purchase\ReadingContext;
use App\Application\Purchase\ReceiptInput;
use App\Application\Purchase\ReceiptReader;
use App\Application\Purchase\RecognizedReceipt;

/**
 * Stands in for Claude in tests: answers with a prepared receipt and remembers what it was asked.
 */
final class FakeReceiptReader implements ReceiptReader
{
    /** @var array<mixed> */
    public array $answer = [
        'store'    => 'SAS',
        'date'     => '2026-11-09',
        'currency' => 'AMD',
        'total'    => '3850',
        'items'    => [
            ['name' => 'Молоко', 'quantity' => '2', 'unit' => 'pcs', 'unit_price' => '600', 'amount' => '1200'],
            ['name' => 'Яблоки', 'quantity' => '1.5', 'unit' => 'kg', 'unit_price' => '900', 'amount' => ''],
            ['name' => 'Хлеб', 'quantity' => '1', 'unit' => 'pcs', 'unit_price' => '', 'amount' => '1400'],
        ],
    ];

    public ?ReceiptInput $lastInput        = null;
    public ?AiCredentials $lastCredentials = null;

    public function provider(): AiProvider
    {
        return AiProvider::Claude;
    }

    public function read(ReceiptInput $input, ReadingContext $context, AiCredentials $credentials): RecognizedReceipt
    {
        $this->lastInput       = $input;
        $this->lastCredentials = $credentials;

        return RecognizedReceipt::fromArray($this->answer);
    }
}
