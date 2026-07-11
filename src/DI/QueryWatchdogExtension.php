<?php

declare(strict_types=1);

namespace Moserra\QueryWatchdog\DI;

use Moserra\QueryWatchdog\Bridge\NetteDatabaseBridge;
use Moserra\QueryWatchdog\Bridge\NextrasDbalLogger;
use Moserra\QueryWatchdog\QueryWatchdog;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

/**
 * Tek satır kurulum: `extensions: queryWatchdog: Moserra\QueryWatchdog\DI\QueryWatchdogExtension`.
 * Container'daki tüm nette/database ve nextras/dbal Connection servislerine kendini bağlar
 * (alt sınıflar dahil — findByType). strict verilmezse %debugMode% kullanılır.
 */
final class QueryWatchdogExtension extends CompilerExtension
{
    public function getConfigSchema(): Schema
    {
        return Expect::structure([
            'budgetPerRequest' => Expect::int(80),
            'duplicateSelectLimit' => Expect::int(5),
            'exactDuplicateLimit' => Expect::int(2),
            'slowQueryMs' => Expect::int(200),
            'strict' => Expect::bool()->nullable()->default(null),
        ]);
    }

    public function loadConfiguration(): void
    {
        $builder = $this->getContainerBuilder();
        $config = $this->getConfig();

        $builder->addDefinition($this->prefix('watchdog'))
            ->setFactory(QueryWatchdog::class, [
                'budget' => $config->budgetPerRequest,
                'duplicateSelectLimit' => $config->duplicateSelectLimit,
                'slowQueryMs' => $config->slowQueryMs,
                'strict' => $config->strict ?? $builder->parameters['debugMode'],
                'exactDuplicateLimit' => $config->exactDuplicateLimit,
            ]);
    }

    public function beforeCompile(): void
    {
        $builder = $this->getContainerBuilder();
        $watchdog = '@' . $this->prefix('watchdog');

        if (class_exists(\Nextras\Dbal\Connection::class)) {
            $logger = null;
            foreach ($builder->findByType(\Nextras\Dbal\Connection::class) as $definition) {
                if (!$definition instanceof ServiceDefinition) {
                    continue;
                }
                $logger ??= $builder->addDefinition($this->prefix('nextrasLogger'))
                    ->setFactory(NextrasDbalLogger::class, [$watchdog]);
                $definition->addSetup('addLogger', [$logger]);
            }
        }

        if (class_exists(\Nette\Database\Connection::class)) {
            foreach ($builder->findByType(\Nette\Database\Connection::class) as $definition) {
                if (!$definition instanceof ServiceDefinition) {
                    continue;
                }
                $definition->addSetup(NetteDatabaseBridge::class . '::attach', ['@self', $watchdog]);
            }
        }
    }
}
