<?php

/**
 * Tekrar kurallarının davranış testi. Bağımlılık yok: strict modda Tracy'ye
 * hiç dokunulmadığı için src doğrudan yüklenir (`php tests/duplicate-rules.php`).
 */

declare(strict_types=1);

require __DIR__ . '/../src/QueryWatchdogException.php';
require __DIR__ . '/../src/QueryWatchdog.php';

use Moserra\QueryWatchdog\QueryWatchdog;
use Moserra\QueryWatchdog\QueryWatchdogException;

$failed = 0;

/** @param list<string> $queries */
function expect(string $label, array $queries, bool $shouldReport): void
{
    global $failed;

    $watchdog = new QueryWatchdog(budget: 999, duplicateSelectLimit: 5, slowQueryMs: 9999, strict: true);
    $reported = false;
    try {
        foreach ($queries as $sql) {
            $watchdog->onQuery($sql, 0.001);
        }
    } catch (QueryWatchdogException) {
        $reported = true;
    }

    $ok = $reported === $shouldReport;
    $failed += $ok ? 0 : 1;
    printf("%s %s\n", $ok ? 'OK  ' : 'FAIL', $label);
}

$read = 'SELECT id, name FROM parties WHERE id = 7';

expect('identical SELECT twice is a memoization miss', [$read, $read], true);

// Yazma kuşağı: "oku → yaz → yeniden oku" meşrudur, ikinci okuma başka satır döndürür.
expect('re-read after UPDATE is not a repeat', [$read, "UPDATE parties SET name = 'x' WHERE id = 7", $read], false);
expect('re-read after DELETE is not a repeat', [$read, 'DELETE FROM parties WHERE id = 7', $read], false);
expect('repeat within the new generation still reports', [$read, 'INSERT INTO parties (id) VALUES (9)', $read, $read], true);

// İşlem defteri veri değiştirmez → kuşağı ilerletmemeli, yoksa kural delinir.
expect('BEGIN/COMMIT do not open a new generation', [$read, 'BEGIN', 'COMMIT', $read], true);
expect('SET LOCAL does not open a new generation', [$read, "SET LOCAL app.tenant_id = 'a'", $read], true);

// Aynı metin, farklı sonuç: varsayım tutmaz.
expect('locking reads are exempt', [
    'SELECT * FROM jobs WHERE state = 1 FOR UPDATE SKIP LOCKED',
    'SELECT * FROM jobs WHERE state = 1 FOR UPDATE SKIP LOCKED',
], false);
expect('sequence reads are exempt', ["SELECT nextval('party_seq')", "SELECT nextval('party_seq')"], false);

// now() işlem başlangıç zamanıdır, işlem içinde sabit → muaf DEĞİL.
expect('now() is not exempt', [
    'SELECT * FROM sales WHERE created_at < now()',
    'SELECT * FROM sales WHERE created_at < now()',
], true);

// Kuşak yalnız birebir-tekrar kuralına uygulanır: döngü içindeki yazma N+1'i masum yapmaz.
expect('shape rule ignores generations', [
    'SELECT * FROM parties WHERE id = 1', 'UPDATE parties SET x = 1 WHERE id = 1',
    'SELECT * FROM parties WHERE id = 2', 'UPDATE parties SET x = 1 WHERE id = 2',
    'SELECT * FROM parties WHERE id = 3', 'UPDATE parties SET x = 1 WHERE id = 3',
    'SELECT * FROM parties WHERE id = 4', 'UPDATE parties SET x = 1 WHERE id = 4',
    'SELECT * FROM parties WHERE id = 5',
], true);

expect('different literals never collide', [
    'SELECT * FROM parties WHERE id = 1',
    'SELECT * FROM parties WHERE id = 2',
], false);

echo $failed === 0 ? "\nall green\n" : "\n{$failed} failed\n";
exit($failed === 0 ? 0 : 1);
