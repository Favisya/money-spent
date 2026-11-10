<?php

declare(strict_types=1);

namespace App\Infrastructure\Rates;

use App\Application\Rates\RateQuote;
use App\Application\Rates\RateSnapshot;
use App\Application\Rates\RateSource;
use App\Application\Rates\RateSourceException;
use DateTimeImmutable;
use SimpleXMLElement;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Central Bank of Armenia SOAP API: rates are AMD per "Amount" units of a currency.
 */
final readonly class CbaRateSource implements RateSource
{
    private const string ENDPOINT    = '/exchangerates.asmx';
    private const string SOAP_ACTION = '"http://www.cba.am/ExchangeRatesByDate"';
    private const string NAMESPACE   = 'http://www.cba.am/';
    private const int MAX_BYTES      = 1_048_576;
    private const int SCALE          = 8;

    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    public function name(): string
    {
        return 'cba.am';
    }

    public function fetch(DateTimeImmutable $date): RateSnapshot
    {
        $requested = $date->setTime(0, 0);

        return $this->parse($this->request($requested), $requested);
    }

    private function request(DateTimeImmutable $date): string
    {
        $envelope = sprintf(
            '<?xml version="1.0" encoding="utf-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
            . '<ExchangeRatesByDate xmlns="%s"><date>%sT00:00:00</date></ExchangeRatesByDate>'
            . '</soap:Body></soap:Envelope>',
            self::NAMESPACE,
            $date->format('Y-m-d'),
        );

        try {
            $response = $this->httpClient->request('POST', self::ENDPOINT, [
                'headers' => [
                    'Content-Type' => 'text/xml; charset=utf-8',
                    'SOAPAction'   => self::SOAP_ACTION,
                ],
                'body' => $envelope,
            ]);
            $content = $response->getContent();
        } catch (ExceptionInterface $exception) {
            throw new RateSourceException('CBA request failed: ' . $exception->getMessage(), 0, $exception);
        }

        if (strlen($content) > self::MAX_BYTES) {
            throw new RateSourceException('CBA response is too large.');
        }

        return $content;
    }

    private function parse(string $xml, DateTimeImmutable $requested): RateSnapshot
    {
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($document === false) {
            throw new RateSourceException('CBA response is not valid XML.');
        }

        $document->registerXPathNamespace('cba', self::NAMESPACE);
        $effective = $this->effectiveDate($document, $requested);
        $nodes     = $document->xpath('//cba:Rates/cba:ExchangeRate') ?: [];
        $quotes    = [];

        foreach ($nodes as $node) {
            $node->registerXPathNamespace('cba', self::NAMESPACE);
            $iso    = self::text($node, 'ISO');
            $amount = self::text($node, 'Amount');
            $rate   = self::text($node, 'Rate');

            if (preg_match('/^[A-Z]{3}$/', $iso) !== 1 || !self::isPositiveDecimal($amount)) {
                continue;
            }

            if (!self::isPositiveDecimal($rate)) {
                continue;
            }

            $quotes[] = new RateQuote($iso, bcdiv($rate, $amount, self::SCALE));
        }

        return new RateSnapshot($effective, $quotes);
    }

    /**
     * CBA answers with the last published date when the requested one has no rates yet.
     */
    private function effectiveDate(SimpleXMLElement $document, DateTimeImmutable $requested): DateTimeImmutable
    {
        $found = $document->xpath('//cba:ExchangeRatesByDateResult/cba:CurrentDate');
        $value = $found === null || $found === false || $found === [] ? '' : substr(trim((string) $found[0]), 0, 10);
        $date  = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $requested->getTimezone());

        if ($date === false || $date > $requested) {
            throw new RateSourceException(sprintf('CBA returned an unexpected date "%s".', $value));
        }

        return $date;
    }

    private static function text(SimpleXMLElement $node, string $name): string
    {
        $found = $node->xpath('cba:' . $name);

        return $found === null || $found === false || $found === [] ? '' : trim((string) $found[0]);
    }

    private static function isPositiveDecimal(string $value): bool
    {
        return is_numeric($value)
            && preg_match('/^\d+(\.\d+)?$/', $value) === 1
            && bccomp($value, '0', self::SCALE) > 0;
    }
}
