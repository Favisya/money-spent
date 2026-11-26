<?php

declare(strict_types=1);

namespace App\Application\Purchase;

use App\Application\Clock\LocalCalendar;
use App\Application\Reference\CurrencyCatalog;
use App\Application\UserError;
use App\Domain\Purchase\Basket;
use App\Infrastructure\Persistence\ReceiptRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Starts a receipt for review: read by the chosen AI model, or empty to be filled by hand.
 */
final readonly class ReceiptRecognizer
{
    public function __construct(
        private AiSettings $settings,
        private ReceiptReaderRegistry $readers,
        private ReceiptNormalizer $normalizer,
        private ReceiptRepository $receipts,
        private CurrencyCatalog $currencies,
        private LocalCalendar $calendar,
    ) {
    }

    /**
     * @return int id of the draft receipt
     */
    public function recognize(ReceiptInput $input, string $language): int
    {
        $provider    = $this->settings->provider();
        $credentials = $this->settings->credentials($provider)
            ?? throw new UserError('Сначала добавь API-ключ в настройках ИИ.');
        $context    = $this->context($language);
        $recognized = $this->readers->for($provider)->read($input, $context, $credentials);
        $content    = $this->normalizer->normalize($recognized, $context);

        if ($content->basket->isEmpty()) {
            throw new UserError('ИИ не нашёл ни одной позиции — сделай фото чётче или заполни чек вручную.');
        }

        return $this->receipts->create(
            new ReceiptDraft($input->source(), $provider, $content, $input->text, $input->images),
            Uuid::v4()->toRfc4122(),
        );
    }

    /**
     * @return int id of an empty draft in the base currency, dated today
     */
    public function blank(): int
    {
        $content = new ReceiptContent(null, $this->calendar->today(), new Basket($this->currencies->base(), []), null);

        return $this->receipts->create(
            new ReceiptDraft(ReceiptSource::Manual, null, $content),
            Uuid::v4()->toRfc4122(),
        );
    }

    private function context(string $language): ReadingContext
    {
        $known = [];

        foreach ($this->currencies->all() as $definition) {
            $known[$definition->currency->code] = $definition->currency;
        }

        return new ReadingContext($this->calendar->today(), $this->currencies->base(), $known, $language);
    }
}
