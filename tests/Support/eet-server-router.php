<?php

declare(strict_types=1);

/*
 * Router of the local EET server (see LocalEetServer). Runs in a separate
 * `php -S 127.0.0.1:<port>` process. Test support code, not package code.
 */

use Gunner42\LaravelEetModule\Tests\Support\EetResponse;
use Gunner42\LaravelEetModule\Tests\Support\SoapMessage;

// PHP errors and deprecations (e.g. from old dependencies on prefer-lowest)
// must never end up in the HTTP response body
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', (string) getenv('EET_TEST_SERVER_DIR').DIRECTORY_SEPARATOR.'php-errors.log');

require __DIR__.'/../../vendor/autoload.php';

$directory = (string) getenv('EET_TEST_SERVER_DIR');
/** @var array{type: string, receivedAt?: string, sleepMs?: int, size?: int} $scenario */
$scenario = json_decode((string) file_get_contents($directory.DIRECTORY_SEPARATOR.'scenario.json'), true, 512, JSON_THROW_ON_ERROR);
$body = (string) file_get_contents('php://input');

file_put_contents(
    $directory.DIRECTORY_SEPARATOR.'requests.jsonl',
    json_encode(['uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''), 'headers' => getallheaders()], JSON_THROW_ON_ERROR)."\n",
    FILE_APPEND | LOCK_EX,
);

try {
    $uuid = (new SoapMessage($body))->uuid();
} catch (Throwable) {
    http_response_code(400);
    echo 'not a SOAP message';

    return true;
}

$confirmation = EetResponse::confirmed($uuid, EetResponse::confirmationCodeFor($uuid, 'ff'))->test('true');

if (isset($scenario['receivedAt'])) {
    $confirmation->receivedAt($scenario['receivedAt']);
}

$xml = $confirmation->toXml();
$size = $scenario['size'] ?? 70000;
$padded = static fn (string $xml, int $size): string => str_replace('</soapenv:Envelope>', str_repeat(' ', max(0, $size - strlen($xml))).'</soapenv:Envelope>', $xml);

header('Content-Type: text/xml; charset=UTF-8');
header('X-Global-Transaction-Id: local-server-1');

switch ($scenario['type']) {
    case 'too_large_length':
    case 'too_large_500':
        $response = $padded($xml, $size);
        http_response_code($scenario['type'] === 'too_large_500' ? 500 : 200);
        header('Content-Length: '.strlen($response));
        echo $response;

        break;

    case 'too_large_chunked':
        // an endless chunked body: a client that does not stop at the limit
        // would wait until the response timeout
        header('Transfer-Encoding: chunked');
        $chunk = str_repeat(' ', 65536);
        $first = substr($xml, 0, (int) strpos($xml, '</soapenv:Envelope>'));
        echo dechex(strlen($first))."\r\n".$first."\r\n";
        flush();
        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline && connection_status() === CONNECTION_NORMAL) {
            echo dechex(strlen($chunk))."\r\n".$chunk."\r\n";
            flush();
            usleep(20_000);
        }

        break;

    case 'redirect':
        http_response_code(302);
        header('Location: http://'.($_SERVER['HTTP_HOST'] ?? '127.0.0.1').'/redirected');
        echo $xml;

        break;

    case 'sleep':
        usleep(($scenario['sleepMs'] ?? 3000) * 1000);
        echo $xml;

        break;

    case 'partial_stall':
        // headers and part of the body arrive, then the server stalls
        http_response_code((int) ($scenario['status'] ?? 500));
        header('Content-Length: '.strlen($xml));
        echo substr($xml, 0, 100);
        flush();
        usleep(($scenario['sleepMs'] ?? 3500) * 1000);
        echo substr($xml, 100);

        break;

    case 'gzip':
        header('Content-Encoding: gzip');
        echo (string) gzencode($xml);

        break;

    default:
        header('Content-Length: '.strlen($xml));
        echo $xml;
}

return true;
