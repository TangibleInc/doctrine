<?php

declare(strict_types=1);

namespace Tangible\Doctrine;

use Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager;
use Doctrine\Migrations\Configuration\Migration\PhpFile;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\AvailableMigration;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\ExecutionResult;
use Doctrine\Migrations\Version\Version;
use Doctrine\ORM\EntityManager;

/**
 * Runs Doctrine migrations in WordPress context.
 *
 * Handles automatic migration execution on plugin activation and updates,
 * with graceful handling for "table already exists" errors.
 */
class MigrationRunner {
    private DependencyFactory $dependencyFactory;
    private string $pluginSlug;

    public function __construct(
        private EntityManager $entityManager,
        private string $migrationsConfigPath,
        string $pluginSlug = 'tangible',
    ) {
        $this->pluginSlug = $pluginSlug;

        $configLoader = new PhpFile($this->migrationsConfigPath);
        $emLoader = new ExistingEntityManager($this->entityManager);
        $this->dependencyFactory = DependencyFactory::fromEntityManager($configLoader, $emLoader);
    }

    /**
     * Check for pending migrations and run them.
     *
     * @return array{success: bool, migrations_executed: int, error?: string}
     */
    public function runPendingMigrations(): array {
        try {
            $this->ensureMetadataStorageExists();

            $newMigrations = $this->getPendingMigrations();

            if (\count($newMigrations) === 0) {
                // Even with no pending migrations, tables may need prefix renaming
                // (e.g., CLI ran migrations first without the WordPress table prefix)
                $this->ensureTablePrefix();

                return [
                    'success' => true,
                    'migrations_executed' => 0,
                ];
            }

            $migrationsExecuted = $this->executeMigrations($newMigrations);
            $this->ensureTablePrefix();
            $this->generateProxyClasses();

            $this->storeStatus([
                'last_check' => time(),
                'migrations_executed' => $migrationsExecuted,
                'status' => 'success',
            ]);

            return [
                'success' => true,
                'migrations_executed' => $migrationsExecuted,
            ];
        } catch (\Exception $e) {
            $this->logError('Migration failed: '.$e->getMessage());

            $this->storeStatus([
                'last_check' => time(),
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'migrations_executed' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check if there are pending migrations without running them.
     */
    public function hasPendingMigrations(): bool {
        try {
            $this->ensureMetadataStorageExists();

            return \count($this->getPendingMigrations()) > 0;
        } catch (\Exception $e) {
            $this->logError('Could not check for pending migrations: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Get list of pending migration versions.
     *
     * @return string[]
     */
    public function getPendingMigrationVersions(): array {
        try {
            $this->ensureMetadataStorageExists();
            $newMigrations = $this->getPendingMigrations();

            return array_map(
                fn (AvailableMigration $m) => (string) $m->getVersion(),
                $newMigrations->getItems()
            );
        } catch (\Exception $e) {
            $this->logError('Could not get pending migrations: '.$e->getMessage());

            return [];
        }
    }

    private function ensureMetadataStorageExists(): void {
        try {
            $metadataStorage = $this->dependencyFactory->getMetadataStorage();
            $metadataStorage->ensureInitialized();
        } catch (\Exception $e) {
            $this->logError('Could not initialize metadata storage: '.$e->getMessage());
        }
    }

    /**
     * @return \Doctrine\Migrations\Metadata\AvailableMigrationsList
     */
    private function getPendingMigrations() {
        $statusCalculator = $this->dependencyFactory->getMigrationStatusCalculator();

        return $statusCalculator->getNewMigrations();
    }

    /**
     * @param \Doctrine\Migrations\Metadata\AvailableMigrationsList $newMigrations
     */
    private function executeMigrations($newMigrations): int {
        $migrator = $this->dependencyFactory->getMigrator();
        $planCalculator = $this->dependencyFactory->getMigrationPlanCalculator();
        $metadataStorage = $this->dependencyFactory->getMetadataStorage();

        $versions = array_map(
            fn (AvailableMigration $m) => $m->getVersion(),
            $newMigrations->getItems()
        );

        $migrationsExecuted = 0;

        foreach ($versions as $version) {
            try {
                $plan = $planCalculator->getPlanForVersions([$version], Direction::UP);

                $config = new MigratorConfiguration();
                $config->setAllOrNothing(false);
                $config->setTimeAllQueries(false);

                $migrator->migrate($plan, $config);
                ++$migrationsExecuted;
            } catch (\Exception $e) {
                if ($this->isTableAlreadyExistsError($e)) {
                    $this->logError('Migration '.$version.' tables already exist, marking as executed');
                    $this->markMigrationAsExecuted($metadataStorage, $version);
                    ++$migrationsExecuted;
                } else {
                    throw $e;
                }
            }
        }

        return $migrationsExecuted;
    }

    private function isTableAlreadyExistsError(\Exception $e): bool {
        $message = $e->getMessage();

        return stripos($message, 'already exists') !== false
            || stripos($message, '1050') !== false;
    }

    /**
     * @param \Doctrine\Migrations\Metadata\Storage\MetadataStorage $metadataStorage
     */
    private function markMigrationAsExecuted($metadataStorage, Version $version): void {
        $metadataStorage->complete(
            new ExecutionResult(
                new Version((string) $version),
                Direction::UP,
                new \DateTimeImmutable(),
            )
        );
    }

    /**
     * Ensure managed tables use the correct prefix from the naming strategy.
     *
     * When migrations run via CLI (no WordPress prefix) and then the plugin
     * loads in WordPress (with prefix), the tables need to be renamed.
     * This method detects unprefixed tables and renames them.
     */
    private function ensureTablePrefix(): void {
        $namingStrategy = $this->entityManager->getConfiguration()->getNamingStrategy();

        if (!$namingStrategy instanceof NamingStrategy) {
            return;
        }

        $fullPrefix = $namingStrategy->getTablePrefix();
        $barePrefix = $this->pluginSlug.'_';

        // If the full prefix equals the bare prefix, no renaming is needed
        if ($fullPrefix === $barePrefix) {
            return;
        }

        $connection = $this->entityManager->getConnection();
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

        foreach ($metadata as $classMetadata) {
            $expectedTable = $classMetadata->getTableName();

            // Derive the unprefixed name by replacing the full prefix with the bare prefix
            if (!str_starts_with($expectedTable, $fullPrefix)) {
                continue;
            }

            $unprefixedTable = $barePrefix.substr($expectedTable, \strlen($fullPrefix));

            // Check if the old unprefixed table exists
            $exists = (bool) $connection->fetchOne(
                'SHOW TABLES LIKE ?',
                [$unprefixedTable]
            );

            if ($exists) {
                $connection->executeStatement(
                    "RENAME TABLE `{$unprefixedTable}` TO `{$expectedTable}`"
                );
            }
        }
    }

    private function generateProxyClasses(): void {
        $metadataFactory = $this->entityManager->getMetadataFactory();
        $proxyFactory = $this->entityManager->getProxyFactory();
        $metadatas = $metadataFactory->getAllMetadata();

        if (!empty($metadatas)) {
            $proxyFactory->generateProxyClasses($metadatas);
        }
    }

    private function storeStatus(array $status): void {
        if (\function_exists('update_option')) {
            update_option($this->pluginSlug.'_migrations_status', $status);
        }
    }

    private function logError(string $message): void {
        if (\function_exists('error_log')) {
            error_log('['.ucfirst($this->pluginSlug).'] '.$message);
        }
    }
}
