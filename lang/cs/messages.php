<?php

declare(strict_types=1);

return [

    'error_codes' => [
        'TemporaryTechnicalError' => 'Dočasná technická chyba, zpráva se odešle znovu',
        'VerificationSucceeded' => 'Zprávu v ověřovacím módu se podařilo zpracovat',
        'InvalidXmlEncoding' => 'Chybné kódování XML',
        'SchemaValidationFailed' => 'Zpráva nevyhověla kontrole XML schématu',
        'InvalidSignature' => 'Neplatný podpis SOAP zprávy',
        'InvalidEicStructure' => 'Neplatná struktura EIČ',
        'MessageTooLarge' => 'Zpráva je příliš velká',
        'ProcessingFailed' => 'Zprávu se nepodařilo zpracovat',
    ],

    'warning_codes' => [
        'EicMismatchesCertificate' => 'EIČ poplatníka se neshoduje s EIČ v certifikátu',
        'InvalidDelegatingEicFormat' => 'Chybný formát EIČ pověřujícího poplatníka',
        'SaleInFuture' => 'Datum a čas uskutečnění tržby je v budoucnosti',
        'SaleTooOld' => 'Datum a čas uskutečnění tržby je výrazně v minulosti',
        'InvalidRegistrationUnitFormat' => 'Číslo evidenční jednotky nemá přidělený formát',
        'DelegatingEicEqualsTaxpayerEic' => 'EIČ pověřujícího poplatníka je shodné s EIČ poplatníka',
    ],

    'sale_statuses' => [
        'Pending' => 'Čeká na odeslání',
        'Confirmed' => 'Potvrzená',
        'Verified' => 'Ověřená',
        'Unconfirmed' => 'Nepotvrzená',
        'Rejected' => 'Odmítnutá',
    ],

    'attempt_outcomes' => [
        'Confirmed' => 'Potvrzeno',
        'Verified' => 'Ověřeno',
        'Rejected' => 'Odmítnuto',
        'TemporaryError' => 'Dočasná chyba',
        'AmbiguousError' => 'Nejednoznačná chyba',
        'Timeout' => 'Vypršela mezní doba odezvy',
        'TransportError' => 'Chyba přenosu',
        'InvalidResponseSignature' => 'Neplatný podpis odpovědi',
        'ResponseMismatch' => 'Odpověď neodpovídá zprávě',
        'CertificateUnavailable' => 'Žádný platný certifikát',
    ],

    'certificate_problems' => [
        'Unreadable' => 'Soubor je poškozený nebo je heslo chybné',
        'MissingPrivateKey' => 'Soubor neobsahuje privátní klíč',
        'KeyMismatch' => 'Privátní klíč nepatří k certifikátu',
        'Expired' => 'Platnost certifikátu skončila',
        'UnsupportedLegacyFormat' => 'Běhové prostředí neumí načíst starší formát PKCS#12; převeďte soubor do PEM',
    ],

    'sale_data_problems' => [
        'InvalidFormat' => 'Hodnota neodpovídá požadovanému formátu',
        'DelegatingEicEqualsTaxpayerEic' => 'EIČ pověřujícího poplatníka je shodné s EIČ poplatníka',
        'TooOld' => 'Tržba je starší než dva roky',
        'BeforeEnvironmentStart' => 'Tržba je starší než začátek prostředí',
        'SignMismatch' => 'Částka má opačné znaménko než celková částka tržby',
        'PartsExceedTotal' => 'Dílčí částky převyšují celkovou částku tržby',
    ],

    'preflight_warnings' => [
        'SaleInFuture' => 'Tržba je o více než dvě hodiny v budoucnosti',
        'UnusualRegistrationUnitNumber' => 'Číslo evidenční jednotky nemá tvar z DIS+',
        'CertificateEicMismatch' => 'CN podpisového certifikátu se neshoduje s EIČ poplatníka',
    ],

    'sending_modes' => [
        'Live' => 'Ostrý mód',
        'Verification' => 'Ověřovací mód',
    ],

    'environments' => [
        'Playground' => 'Neprodukční prostředí (playground)',
        'Production' => 'Produkční prostředí',
    ],

    'renewal_statuses' => [
        'Requesting' => 'Žádost o certifikát',
        'InProcess' => 'Čeká se na vydání',
        'Downloading' => 'Stahování certifikátu',
        'Acknowledging' => 'Potvrzení převzetí',
        'Completed' => 'Dokončeno',
        'Failed' => 'Selhalo',
    ],

    'renewal_problems' => [
        'NotProductionCertificate' => 'Obnovují se jen produkční certifikáty',
        'CertificateNotValid' => 'Certifikát není platný',
        'CertificateUnusable' => 'Privátní klíč certifikátu nejde dešifrovat',
        'AlreadyRenewed' => 'Certifikát už byl obnoven',
        'TemporaryError' => 'Dočasná chyba API CA EET; další běh to zkusí znovu',
        'UnknownPendingRequest' => 'V CA EET čeká neznámá žádost o certifikát; dokončete ji v DIS+ nebo počkejte na její ukončení',
        'Rejected' => 'CA EET žádost zamítla',
        'AlreadyFinished' => 'CA EET žádost už ukončila',
        'RequestFailed' => 'CA EET volání odmítla',
        'CertificateUnreadable' => 'Stažený certifikát nejde načíst',
        'CertificateMismatch' => 'Stažený certifikát neodpovídá obnovovanému certifikátu',
    ],

];
