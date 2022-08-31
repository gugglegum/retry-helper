<?php

require_once __DIR__  . '/../vendor/autoload.php';

$request = new \GuzzleHttp\Psr7\Request("GET", "https://example.com");

/** @var \Psr\Http\Message\ResponseInterface $response */
$response = (new \gugglegum\RetryHelper\RetryHelper())
    ->setIsTemporaryException(function(\Throwable $e): bool {
        return $e instanceof \GuzzleHttp\Exception\ServerException
            || $e instanceof \GuzzleHttp\Exception\ConnectException;
    })
    ->execute(function() use ($request) {
        return (new \GuzzleHttp\Client())->send($request);
    }, 10);

echo $response->getBody()->getContents() . "\n";
