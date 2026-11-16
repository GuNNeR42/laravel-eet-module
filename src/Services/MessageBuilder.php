<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DOMDocument;
use Gunner42\LaravelEetModule\Data\MessageData;
use Gunner42\LaravelEetModule\Data\MessageHeader;
use LogicException;

/**
 * Builds the SOAP envelope with the data message Trzba (R22, R24, R30).
 */
final class MessageBuilder
{
    public const string NS_SOAP = 'http://schemas.xmlsoap.org/soap/envelope/';

    public const string NS_EET = 'http://fs.gov.cz/eet/schema/v4';

    public const string NS_WSSE = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';

    public const string NS_WSU = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';

    public const string NS_DS = 'http://www.w3.org/2000/09/xmldsig#';

    public const string NS_EXC_C14N = 'http://www.w3.org/2001/10/xml-exc-c14n#';

    public const string X509V3 = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3';

    public const string BASE64_BINARY = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary';

    /**
     * Date and time in the EET form in the configured timezone, without
     * fractions of a second (R24).
     */
    public static function formatDateTime(DateTimeInterface $moment): string
    {
        return CarbonImmutable::instance($moment)
            ->setTimezone(ConfigurationValidator::string('timezone'))
            ->format('Y-m-d\TH:i:sP');
    }

    /**
     * The envelope with an empty Header, ready to be signed.
     */
    public function envelope(MessageHeader $header, MessageData $data): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');

        if (! $document->loadXML($this->xml($header, $data), LIBXML_NONET)) {
            throw new LogicException('The data message could not be built.');
        }

        return $document;
    }

    /**
     * The unsigned envelope (used by Eet::fake(), R57).
     */
    public function unsigned(MessageHeader $header, MessageData $data): string
    {
        return (string) $this->envelope($header, $data)->saveXML();
    }

    private function xml(MessageHeader $header, MessageData $data): string
    {
        $hlavicka = '<v4:Hlavicka'
            .self::attribute('uuid_zpravy', $header->messageUuid)
            .self::attribute('dat_odesl', $header->sentAt)
            .self::attribute('prvni_zaslani', $header->isFirstSubmission ? 'true' : 'false')
            .($header->verificationMode ? self::attribute('overeni', 'true') : '')
            .'/>';

        $dataElement = '<v4:Data'
            .self::attribute('eic_popl', $data->eic)
            .($data->delegatingEic !== null ? self::attribute('eic_poverujiciho', $data->delegatingEic) : '')
            .($data->delegatedByMultipleTaxpayers ? self::attribute('povereni_vice_popl', 'true') : '')
            .self::attribute('id_jednotky', (string) $data->registrationUnitNumber)
            .self::attribute('id_pokl', $data->cashRegisterIdentifier)
            .self::attribute('porad_cis', $data->sequenceNumber)
            .self::attribute('dat_trzby', $data->occurredAt)
            .self::attribute('celk_trzba', $data->totalAmount->toEetString())
            .($data->amountForLaterDrawing !== null ? self::attribute('urceno_cerp_zuct', $data->amountForLaterDrawing->toEetString()) : '')
            .($data->drawnAmount !== null ? self::attribute('cerp_zuct', $data->drawnAmount->toEetString()) : '')
            .'/>';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soapenv:Envelope xmlns:soapenv="'.self::NS_SOAP.'" xmlns:v4="'.self::NS_EET.'">'
            .'<soapenv:Header/>'
            .'<soapenv:Body xmlns:wsu="'.self::NS_WSU.'" wsu:Id="id-'.$header->messageUuid.'">'
            .'<v4:Trzba>'.$hlavicka.$dataElement.'</v4:Trzba>'
            .'</soapenv:Body>'
            .'</soapenv:Envelope>';
    }

    private static function attribute(string $name, string $value): string
    {
        return ' '.$name.'="'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'"';
    }
}
