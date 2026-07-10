<?php

declare(strict_types=1);

namespace Moserra\QueryWatchdog;

use Tracy\Debugger;
use Tracy\ILogger;

/**
 * Framework-bağımsız çekirdek. Sorgular normalize edilmiş parmak iziyle sayılır:
 * literal'ler ?'ye indirgendiği için per-row `WHERE id = 5` çağrıları da aynı parmak
 * ize düşer — N+1 ve memoize edilmemiş tekrar birlikte yakalanır. Tekrar limiti
 * yalnız SELECT'e uygulanır; bütçe her sorguyu sayar. strict = throw, değilse
 * structured Tracy log. Yavaş sorgu süresi nondeterministik olduğundan asla throw
 * etmez, iki modda da log'lanır.
 */
final class QueryWatchdog
{
    private const IgnoredPrefixes = ['BEGIN', 'COMMIT', 'ROLLBACK', 'SET ', 'SAVEPOINT', 'RELEASE', 'START TRANSACTION', 'EXPLAIN'];
    private const ReportTopFingerprints = 5;

    private int $total = 0;

    /** @var array<string, int> */
    private array $selectCounts = [];

    /** @var array<string, string> */
    private array $examples = [];

    /** @var array<string, true> */
    private array $slowReported = [];

    private bool $budgetReported = false;

    public function __construct(
        private readonly int $budget,
        private readonly int $duplicateSelectLimit,
        private readonly int $slowQueryMs,
        private readonly bool $strict,
    ) {
    }

    public function onQuery(string $sql, float $timeTaken): void
    {
        $trimmed = ltrim($sql);
        foreach (self::IgnoredPrefixes as $prefix) {
            if (stripos($trimmed, $prefix) === 0) {
                return;
            }
        }

        $elapsedMs = (int) round($timeTaken * 1000);
        if ($elapsedMs > $this->slowQueryMs) {
            $slowKey = self::fingerprint($trimmed);
            if (!isset($this->slowReported[$slowKey])) {
                $this->slowReported[$slowKey] = true;
                Debugger::log([
                    'event' => 'query_watchdog.slow_query',
                    'ctx' => ['ms' => $elapsedMs, 'limit_ms' => $this->slowQueryMs, 'sql' => $trimmed],
                ], ILogger::WARNING);
            }
        }

        $this->total++;
        if ($this->total > $this->budget && !$this->budgetReported) {
            $this->budgetReported = true;
            $this->report(
                sprintf('Query budget exceeded: %d queries in one request (budget %d).', $this->total, $this->budget),
                ['total' => $this->total, 'budget' => $this->budget, 'top' => $this->topOffenders()],
            );
        }

        if (stripos($trimmed, 'SELECT') !== 0) {
            return;
        }

        $fingerprint = self::fingerprint($trimmed);
        $count = ($this->selectCounts[$fingerprint] ?? 0) + 1;
        $this->selectCounts[$fingerprint] = $count;
        $this->examples[$fingerprint] ??= $trimmed;

        if ($count === $this->duplicateSelectLimit) {
            $this->report(
                sprintf(
                    "Duplicate SELECT: same query shape ran %d× in one request — batch it (IN-list/JOIN) or memoize the result.\nShape: %s\nExample: %s",
                    $count,
                    $fingerprint,
                    $this->examples[$fingerprint],
                ),
                ['count' => $count, 'shape' => $fingerprint, 'example' => $this->examples[$fingerprint]],
            );
        }
    }

    /** @param array<string, mixed> $ctx */
    private function report(string $message, array $ctx): void
    {
        if ($this->strict) {
            throw new QueryWatchdogException($message);
        }

        Debugger::log(['event' => 'query_watchdog.violation', 'ctx' => $ctx], ILogger::WARNING);
    }

    /** @return list<string> */
    private function topOffenders(): array
    {
        $counts = $this->selectCounts;
        arsort($counts);
        $lines = [];
        foreach (array_slice($counts, 0, self::ReportTopFingerprints, true) as $fingerprint => $count) {
            $lines[] = sprintf('%d× %s', $count, $fingerprint);
        }

        return $lines;
    }

    private static function fingerprint(string $sql): string
    {
        $shape = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", '?', $sql);
        $shape = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', (string) $shape);
        $shape = preg_replace('/\?(?:\s*,\s*\?)+/', '?', (string) $shape);
        $shape = preg_replace('/\s+/', ' ', (string) $shape);

        return trim((string) $shape);
    }
}
