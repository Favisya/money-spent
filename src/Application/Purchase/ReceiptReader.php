<?php

declare(strict_types=1);

namespace App\Application\Purchase;

use App\Application\UserError;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * An AI model that turns a receipt photo or a typed list into positions.
 */
#[AutoconfigureTag(self::TAG)]
interface ReceiptReader
{
    public const string TAG = 'app.receipt_reader';

    public function provider(): AiProvider;

    /**
     * @throws UserError when the model cannot be reached or does not answer usefully
     */
    public function read(ReceiptInput $input, ReadingContext $context, AiCredentials $credentials): RecognizedReceipt;
}
