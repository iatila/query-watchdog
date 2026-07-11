<?php

declare(strict_types=1);

namespace Moserra\QueryWatchdog;

use Tracy\Debugger;
use Tracy\ILogger;

/**
 * Framework-bağımsız çekirdek. İki tekrar kuralı (yalnız SELECT):
 *  - ŞEKİL (duplicateSelectLimit, vars. 5): literal'ler ?'ye indirgenir → per-row
 *    `WHERE id = 5` çağrıları aynı parmak ize düşer, N+1'i yakalar.
 *  - EXACT (exactDuplicateLimit, vars. 2): literaller KORUNUR (yalnız boşluk normalize).
 *    Birebir aynı SQL tekrar ederse dönen satırlar da aynıdır → memoize eksikliği;
 *    yüksek isabet (farklı literal → farklı key, yanlış-pozitif yok), düşük eşik.
 * Bütçe her sorguyu sayar. strict = throw, değilse structured Tracy log. Yavaş sorgu
 * süresi nondeterministik olduğundan asla throw etmez, iki modda da log'lanır.
 */
final class QueryWatchdog
{
    private const IgnoredPrefixes = ['BEGIN', 'COMMIT', 'ROLLBACK', 'SET ', 'SAVEPOINT', 'RELEASE', 'START TRANSACTION', 'EXPLAIN'];

    /**
     * Sorguda geçerse tamamen yok sayılır (bütçe/tekrar dışı). Framework şema introspection'ı
     * (ORM/Explorer FK & kolon reflection'ı) tablo başına aynı information_schema sorgusunu çalıştırır
     * → uygulama-fixlenemez false-positive; N+1 değildir. Bkz Nette Database Structure reflection.
     */
    private const IgnoredSubstrings = ['information_schema', 'pg_catalog', 'sqlite_master'];

    private const ReportTopFingerprints = 5;

    private int $total = 0;

    /** @var array<string, int> */
    private array $selectCounts = [];

    /** @var array<string, string> */
    private array $examples = [];

    /** @var array<string, int> exact SQL (literaller korunur, yalnız boşluk normalize) → tekrar sayısı */
    private array $exactCounts = [];

    /** @var array<string, string> */
    private array $exactExamples = [];

    /** @var array<string, true> */
    private array $slowReported = [];

    private bool $budgetReported = false;

    public function __construct(
        private readonly int $budget,
        private readonly int $duplicateSelectLimit,
        private readonly int $slowQueryMs,
        private readonly bool $strict,
        private readonly int $exactDuplicateLimit = 2,
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
        foreach (self::IgnoredSubstrings as $needle) {
            if (stripos($trimmed, $needle) !== false) {
                return;   // framework şema reflection'ı — sayma (false-positive)
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

        // Exact-duplicate: BİREBİR aynı SQL (literaller dahil) tekrar ederse dönen satırlar da aynıdır →
        // her zaman israf (memoize eksik). Şekil-tekrarından ayrı, düşük eşik (vars. 2) + yüksek isabet:
        // "WHERE id=1" vs "id=2" farklı literaller → aynı exact-key'e düşmez, yanlış-pozitif yok.
        $exactKey = trim((string) preg_replace('/\s+/', ' ', $trimmed));
        $exactHash = md5($exactKey);
        $exactCount = ($this->exactCounts[$exactHash] ?? 0) + 1;
        $this->exactCounts[$exactHash] = $exactCount;
        $this->exactExamples[$exactHash] ??= $exactKey;

        if ($exactCount === $this->exactDuplicateLimit) {
            $this->report(
                sprintf(
                    "Identical SELECT ran %d× in one request — same SQL returns the same rows; memoize or cache the result (one call, reuse it).\nSQL: %s",
                    $exactCount,
                    $this->exactExamples[$exactHash],
                ),
                ['count' => $exactCount, 'kind' => 'exact_duplicate', 'sql' => $this->exactExamples[$exactHash]],
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
