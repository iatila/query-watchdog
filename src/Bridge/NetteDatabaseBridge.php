<?php

declare(strict_types=1);

namespace Moserra\QueryWatchdog\Bridge;

use Moserra\QueryWatchdog\QueryWatchdog;
use Nette\Database\Connection;
use Nette\Database\ResultSet;

final class NetteDatabaseBridge
{
    public static function attach(Connection $connection, QueryWatchdog $watchdog): void
    {
        $connection->onQuery[] = static function (Connection $c, mixed $result) use ($watchdog): void {
            if ($result instanceof ResultSet) {
                $watchdog->onQuery($result->getQueryString(), $result->getTime());
            }
        };
    }
}
