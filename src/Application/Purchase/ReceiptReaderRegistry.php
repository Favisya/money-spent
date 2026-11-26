<?php

declare(strict_types=1);

namespace App\Application\Purchase;

use LogicException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class ReceiptReaderRegistry
{
    /**
     * @param iterable<ReceiptReader> $readers
     */
    public function __construct(
        #[AutowireIterator(ReceiptReader::TAG)]
        private iterable $readers,
    ) {
    }

    public function for(AiProvider $provider): ReceiptReader
    {
        foreach ($this->readers as $reader) {
            if ($reader->provider() === $provider) {
                return $reader;
            }
        }

        throw new LogicException(sprintf('No receipt reader for "%s".', $provider->value));
    }
}
