<?php

declare(strict_types=1);

namespace Moserra\QueryWatchdog\Bridge;

use Moserra\QueryWatchdog\QueryWatchdog;
use Nextras\Dbal\Drivers\Exception\DriverException;
use Nextras\Dbal\ILogger;
use Nextras\Dbal\Result\Result;

final class NextrasDbalLogger implements ILogger
{
    public function __construct(
        private readonly QueryWatchdog $watchdog,
    ) {
    }

    public function onConnect(): void
    {
    }

    public function onDisconnect(): void
    {
    }

    public function onQuery(string $sqlQuery, float $timeTaken, ?Result $result): void
    {
        $this->watchdog->onQuery($sqlQuery, $timeTaken);
    }

    public function onQueryException(string $sqlQuery, float $timeTaken, ?DriverException $exception): void
    {
    }
}
