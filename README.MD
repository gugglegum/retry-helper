# Retry Helper

When you perform some action that may not succeed on the first try (e.g. a request to a remote server), you may need some kind of error handling, retrying, make a delay between attempts, and stop if the maximum number of retries reached.

This simple package contains the `RetryHelper` class, which simplifies error handling, retries, delaying and logging. It's quite flexibly configured through the use of callback functions and supports standardized **PHP-FIG/PSR-3** logging. A section of potentially problematic code should be wrapped in an anonymous function (`Closure`, `callable`) and passed to the `execute()` method. This method determines the error only by the exception thrown inside the anonymous function. Therefore, if your code doesn't throw an exception on error (for example, if you use `curl_exec()` function which simply returns `false` on error), then you need to check return value and throw an exception inside this function.

Here is the simplest example that tries to get response from an HTTP server using the **GuzzleHttp** package, up to 10 attempts:
                                                                       
```php
$request = new \GuzzleHttp\Psr7\Request("GET", "https://example.com");

$response = (new \gugglegum\RetryHelper\RetryHelper())
    ->execute(function() use ($request) {
        return (new \GuzzleHttp\Client())->send($request);
    }, 10);

echo $response->getBody()->getContents() . "\n";
```

In this example the code that may fail here is wrapped into anonymous function and passed as the first argument of `execute()` method. The second argument defines maximum number of attempts (in this example 10). In most cases this code will execute successfully on the first try, the return value of the anonymous function will be redirected to the return value of the `execute()` method and immediately saved in the `$response` variable. But if you have unstable Internet connection, it may take several attempts to get a response. If your Internet completely doesn't work or the website is down, once the maximum number of attempts is reached, the execution will be terminated. The exception thrown inside the anonymous function at the last attempt will be re-thrown to the top.

Since this is the simplest example, it uses some default behaviour which we will override in the next examples. By default, it will retry on every error (exception), no matter what kind of error. In some cases this may be overkill. For example, if you have an "authentication error", there's not much reason to try again and again with the same credentials. Generally, you don't need to try again if you receive HTTP 4xx status code. All 400-th statuses means that problem is on client side (wrong password, no access, wrong URL, etc.) Thereby we can divide all errors on "temporary" (which may go away on next try) and "permanent" (which will not disappear). The `RetryHelper` allows you to define callback function which will be called after each unsuccessful attempt and will decide is this error (exception) is temporary or not. If it returns `true` (error is temporary) then new attempt will be performed (except if maximum number of attempts reached). If it returns `false` it will immediately stop trying.
So let's take a look on the next example which contains this special logic:

```php
$request = new \GuzzleHttp\Psr7\Request("GET", "https://example.com");

$response = (new \gugglegum\RetryHelper\RetryHelper())
    ->setIsTemporaryException(function(\Throwable $e): bool {
        return $e instanceof \GuzzleHttp\Exception\ServerException
            || $e instanceof \GuzzleHttp\Exception\ConnectException;
    })
    ->execute(function() use ($request) {
        return (new \GuzzleHttp\Client())->send($request);
    }, 10);

echo $response->getBody()->getContents() . "\n";
```

By default, all exceptions are considered temporary. But in this example we restricted temporary exceptions to exceptions of 2 specific classes: `ServerException` and `ConnectException`. The exceptions of all other classes (e.g. `ClientException`) will be considered permanent and attempts will be stopped. Thus, we will not hammer the remote server for nothing. Inside this anonymous function, you can check for exception class, code or parse exception message.

To prevent overloading the remote server `RetryHelper` makes a delay between attempts. By default, this delay is random and depends on the current attempt number. After the first attempt it makes random delay between 0 and 10 seconds (including fractional values, for example, 5.237 seconds), after second attempt - between 0 and 20 seconds, after third - between 0 and 30 seconds and so on. This behaviour is optimal in most cases. Using a fractional seconds allow better solve conflicts of concurrent processes that are started by cron almost at the same time. For example, fractional seconds delay might better solve the MySQL problem "Deadlock found when trying to get lock; try restarting transaction". But if you need your own delay mechanism, you can override the default callback function that returns the delay before the next attempt using `setDelayBeforeNextAttempt()` method.

You may also need a special event handler when it ended unsuccessfully, i.e. when either the attempts have ended or the last attempt was permanent (not temporary). Set it with `setOnFailure()` by passing a callback function to it. When called, it will get an exception object and an attempt number, i.e. the signature of callback function is `function(\Throwable $e, int $attempt): void`. In this handler, you can perform some action and/or throw a new exception with a modified message and/or code (see the next example).

Finally, you may want to show in the log or STDOUT/STDERR stream the messages about all failed attempts: what exceptions occurred, numbers of attempts, delay duration. In this case `RetryHelper` supports the standard PHP-FIG/PSR-3 interface for logging. Using the `setLogger()` method you can define you custom logger (which should only implement `\Psr\Log\LoggerInterface`) and it will receive messages during performing attempts. If the code executed successfully on the first attempt, no messages will be sent to the logger. By default, there is no logging.

Here's an example that implements all the features mentioned above, plus try-catch for an exception that will be thrown if the maximum number of attempts is reached or if the thrown exception is not considered temporary. In addition, this example makes an exception for connection errors related to "Could not resolve host". You can see such an error if the domain name does not exist. So in this example, the script will stop retries after this error. This is just an example to show the flexibility of the RetryHelper.

```php
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
            public function log($level, string|Stringable $message, array $context = []): void
            {
                echo "[" . strtoupper($level) . "] {$message}\n";
            }
        })
        ->execute(function() use ($request) {
            return (new \GuzzleHttp\Client())->send($request);
        }, 10);

    echo $response->getBody()->getContents() . "\n";

} catch (\Throwable $e) {
    echo "\nExiting due to an error: {$e->getMessage()}\n";
}
```

If you need the logger inside your main function, you can pass it via `use ($logger)` syntax. If you don't want to create a full-fledged logger class for single use, you can use object of anonymous class:

```php
$logger = new class extends \Psr\Log\AbstractLogger {
    public function log($level, string|Stringable $message, array $context = []): void
    {
        echo "[" . strtoupper($level) . "] {$message}\n";
    }
};
$request = new \GuzzleHttp\Psr7\Request("GET", "https://example.com");
$response = (new \gugglegum\RetryHelper\RetryHelper())
    ->execute(function() use ($request, $logger) {
        $logger->debug("Send GET request");         // <------ Here we use logger inside main callback
        return (new \GuzzleHttp\Client())->send($request);
    }, 10);
```

## Callback functions

There are 3 types of additional callback functions that you can provide in `RetryHelper`. Here are the arguments they receive and the values they should return.

### The `setIsTemporaryException()` method

```php
function(\Throwable $e): bool { ... }
```

The function is called after each failed attempt and is used to determine whether to continue the attempts. By default, this function always returns `true`.

#### Arguments

1. `$e` is an exception object caught on last attempt

#### Return value

Returns the `bool` value, where `true` means that exception in `$e` is temporary and new attempts may solve the problem, `false` means that it's permanent and no need to repeat.

### The `setDelayBeforeNextAttempt()` method

```php
function(int $attempt): float|int { ... }
```

The function is called after each failed attempt and determines delay in seconds before next attempt.

#### Arguments

1. `$attempt` is a number of last attempt (starting from 1)

#### Return value

Returns the `float` or `int` value with number of seconds.

### The `setOnFailure()` method

```php
function(\Throwable $e, int $attempt): void { ... }
```

The function is called if all attempts failed: maximum number of attempts reached, or we got an exception which is considered not temporary (by callback function defined in `setIsTemporaryException()`).

Here you can print some text and close the network connection. You can also throw a specific exception here, but even if not, the original exception caught inside the `execute()` method will be automatically rethrown.

#### Arguments

1. `$e` is an exception object caught on last attempt
2. `$attempt` is a number of last attempt (starting from 1)

No return value.

## Installation

Pretty simple, like all other Composer packages:

```
composer require gugglegum/retry-helper
```
