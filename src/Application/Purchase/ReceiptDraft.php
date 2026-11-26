<?php

declare(strict_types=1);

namespace App\Application\Purchase;

/**
 * A receipt about to be stored for review: recognized by a model or started empty by hand.
 */
final readonly class ReceiptDraft
{
    /**
     * @param list<ReceiptImage> $images
     */
    public function __construct(
        public ReceiptSource $source,
        public ?AiProvider $provider,
        public ReceiptContent $content,
        public ?string $sourceText = null,
        public array $images = [],
    ) {
    }
}
