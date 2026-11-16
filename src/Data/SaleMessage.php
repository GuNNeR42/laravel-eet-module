<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Data;

use DOMDocument;
use DOMElement;
use Gunner42\LaravelEetModule\Services\MessageBuilder;
use LogicException;

/**
 * A sent data message parsed back into its values (R57). Amounts and times
 * are in the EET format.
 */
final readonly class SaleMessage
{
    public function __construct(
        public string $messageUuid,
        public string $sentAt,
        public bool $isFirstSubmission,
        public bool $verificationMode,
        public string $eic,
        public ?string $delegatingEic,
        public bool $delegatedByMultipleTaxpayers,
        public int $registrationUnitId,
        public string $cashRegisterId,
        public string $sequenceNumber,
        public string $occurredAt,
        public string $totalAmount,
        public ?string $amountForLaterDrawing,
        public ?string $drawnAmount,
        public string $xml,
    ) {}

    public static function fromXml(string $xml): self
    {
        $document = new DOMDocument;

        if (! $document->loadXML($xml, LIBXML_NONET)) {
            throw new LogicException('The data message is not well-formed.');
        }

        $header = self::element($document, 'Hlavicka');
        $data = self::element($document, 'Data');
        $optional = static fn (DOMElement $element, string $name): ?string => $element->hasAttribute($name) ? $element->getAttribute($name) : null;

        return new self(
            $header->getAttribute('uuid_zpravy'),
            $header->getAttribute('dat_odesl'),
            $header->getAttribute('prvni_zaslani') === 'true',
            $header->getAttribute('overeni') === 'true',
            $data->getAttribute('eic_popl'),
            $optional($data, 'eic_poverujiciho'),
            $data->getAttribute('povereni_vice_popl') === 'true',
            (int) $data->getAttribute('id_jednotky'),
            $data->getAttribute('id_pokl'),
            $data->getAttribute('porad_cis'),
            $data->getAttribute('dat_trzby'),
            $data->getAttribute('celk_trzba'),
            $optional($data, 'urceno_cerp_zuct'),
            $optional($data, 'cerp_zuct'),
            $xml,
        );
    }

    private static function element(DOMDocument $document, string $localName): DOMElement
    {
        $element = $document->getElementsByTagNameNS(MessageBuilder::NS_EET, $localName)->item(0);

        if (! $element instanceof DOMElement) {
            throw new LogicException("The data message has no {$localName}.");
        }

        return $element;
    }
}
