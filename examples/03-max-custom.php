<?php

require_once __DIR__  . '/../vendor/autoload.php';

$request = new \GuzzleHttp\Psr7\Request("GET", "https://example.com");

try {
    /** @var \Psr\Http\Message\ResponseInterface $response */
    $response = (new \gugglegum\RetryHelper\RetryHelper())
        ->setIsTemporaryException(function(\Throwable $e): bool {
            return $e instanceof \GuzzleHttp\Exception\ServerException
                || ($e instanceof \GuzzleHttp\Exception\ConnectException && !str_contains($e->getMessage(), 'Could not resolve host'));
        })
        ->setDelayBeforeNextAttempt(function(int $attempt): float|int {
            return $attempt * 5;
        })
        ->setOnFailure(function(\Throwable $e, int $attempt): void {
            throw new RuntimeException($e->getMessage() . " (attempt " . $attempt . ")", $e->getCode(), $e);
        })
        ->setLogger(new class extends \Psr\Log\AbstractLogger {
            public function log($level, string|Stringable $message, array $context = []): void {
                echo "[" . strtoupper($level) . "] {$message}\n";
            }
        })
        ->execute(function() use ($request) {
            return (new \GuzzleHttp\Client())->send($request);
        }, 10);

    echo $response->getBody()->getContents() . "\n";

} catch (Throwable $e) {
    echo "\nExiting due to an error: {$e->getMessage()}\n";
}
