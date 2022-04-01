<?php /** @noinspection PhpUnnecessaryCurlyVarSyntaxInspection */

declare(strict_types=1);

namespace gugglegum\RetryHelper;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * RetryHelper makes it easy to handle errors, retries, delay and log sections of code that may fail from time to time,
 * and when repetition is the solution.
 */
class RetryHelper
{
    /**
     * Callback function that decides whether to make the next attempt or not
     *
     * @var callable
     */
    private $isTemporaryException;

    /**
     * Callback function that returns a number of seconds for delay before next attempt (can be fractional).
     *
     * @var callable
     */
    private $delayBeforeNextAttempt;

    /**
     *
     *
     * @var callable
     */
    private $onFailure;

    /**
     * Optional logger that will receive messages about errors and retries
     */
    private LoggerInterface|null $logger = null;

    /** @noinspection PhpUnusedParameterInspection */
    public function __construct()
    {
        $this->setIsTemporaryException(function(Throwable $e): bool {
            return true;
        });
        $this->setDelayBeforeNextAttempt(function(int $attempt): float|int {
            return mt_rand(0, $attempt * 10000 - 1) / 1000;
        });
        $this->setOnFailure(function(Throwable $e, int $attempt): void {});
    }

    /**
     * Executes some user-defined callback function $action 1 time if all is OK and several times (up to $maxAttempts)
     * if an exception is throwing until it will be executed without exception. When exception was thrown optional
     * callback function $isTemporaryException receives exception as an argument and returns TRUE if error is
     * temporary and this is reasonable to continue attempts. On false attempts will be stopped before $maxAttempts
     * limit reached.
     *
     * @param callable $action      Callback function with main action to perform
     * @param int $maxAttempts      Stop repeating action after $maxAttempt
     * @return mixed                Result value is fully dependent on user-defined callback function
     * @throws Throwable
     */
    public function execute(callable $action, int $maxAttempts): mixed
    {
        $attempt = 0;
        do {
            $attempt++;
            if ($attempt > 1) {
                $this->logger?->notice("Retrying, attempt #{$attempt}");
            }
            try {
                $result = call_user_func($action, $attempt);
                break;
            } catch (Throwable $e) {
                $this->logger?->error("Got " . get_class($e) . ": {$e->getMessage()}");
                if ($attempt < $maxAttempts) {
                    if ($this->isTemporaryException === null || call_user_func($this->isTemporaryException, $e, $attempt)) {
                        $delay_sec = call_user_func($this->delayBeforeNextAttempt, $attempt);
                        $this->logger?->info("Sleep " . number_format($delay_sec, 2) . " seconds until next try");
                        usleep((int) ($delay_sec * 1000000));
                        continue;
                    }
                }
                call_user_func($this->onFailure, $e, $attempt);
                throw $e;
            }
        } while (true);
        return $result;
    }

    /**
     * @return callable
     */
    public function getIsTemporaryException(): callable
    {
        return $this->isTemporaryException;
    }

    /**
     * @param callable $isTemporaryException
     * @return self
     */
    public function setIsTemporaryException(callable $isTemporaryException): self
    {
        $this->isTemporaryException = $isTemporaryException;
        return $this;
    }

    /**
     * @return callable
     */
    public function getDelayBeforeNextAttempt(): callable
    {
        return $this->delayBeforeNextAttempt;
    }

    /**
     * @param callable $delayBeforeNextAttempt
     * @return self
     */
    public function setDelayBeforeNextAttempt(callable $delayBeforeNextAttempt): self
    {
        $this->delayBeforeNextAttempt = $delayBeforeNextAttempt;
        return $this;
    }

    /**
     * @param callable $onFailure
     * @return self
     */
    public function setOnFailure(callable $onFailure): self
    {
        $this->onFailure = $onFailure;
        return $this;
    }

    /**
     * @return LoggerInterface|null
     */
    public function getLogger(): LoggerInterface|null
    {
        return $this->logger;
    }

    /**
     * @param LoggerInterface|null $logger
     * @return $this
     */
    public function setLogger(LoggerInterface|null $logger): self
    {
        $this->logger = $logger;
        return $this;
    }
}
