<?php

require_once __DIR__  . '/../vendor/autoload.php';

$request = new \GuzzleHttp\Psr7\Request("GET", "https://example.com");

$response = (new \gugglegum\RetryHelper\RetryHelper())
    ->execute(function() use ($request) {
        return (new \GuzzleHttp\Client())->send($request);
    }, 10);

echo $response->getBody()->getContents() . "\n";
