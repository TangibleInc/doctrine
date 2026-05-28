<?php

declare(strict_types=1);

namespace Tangible\Doctrine\Tests;

use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use Tangible\Doctrine\EntityManagerFactory;
use Tangible\Doctrine\MigrationRunner;

/**
 * Integration tests for MigrationRunner against a real MySQL/MariaDB.
 *
 * The behaviours under test (FK-aware DROP, SHOW TABLES, RENAME TABLE,
 * "table already exists" handling) are MySQL-specific, so these run against a
 * live server and skip cleanly when one is not reachable. Connection comes
 * from env (WORDPRESS_DB_HOST, DOCTRINE_TEST_DB_USER/PASSWORD, with sensible
 * dev-container defaults); a dedicated `tangible_doctrine_test` database is
 * auto-created so nothing touches a real schema's migration history.
 */
final class MigrationRunnerTest extends TestCase {
    private const PLUGIN_SLUG = 'tngtest';
    private const FULL_PREFIX = 'wp_tngtest_';
    private const BARE_PREFIX = 'tngtest_';

    private ?EntityManager $em = null;

    protected function setUp(): void {
        $this->em = $this->makeEntityManager();
        $this->cleanupTables();
    }

    protected function tearDown(): void {
        if ($this->em !== null) {
            $this->cleanupTables();
            $this->em->getConnection()->close();
        }

        EntityManagerFactory::reset();
        $this->em = null;
    }

    /**
     * Regression test for the recurring 1451 seen in production: leftover
     * unprefixed tables carried an inter-table FK (item.resource_id →
     * resource), and ensureTablePrefix() dropped them in metadata order, so the
     * parent could be dropped while its child still referenced it. The fix
     * disables FK checks around the drops.
     */
    public function testEnsureTablePrefixDropsBareDuplicatesDespiteForeignKeys(): void {
        $pdo = $this->pdo();

        // Stale unprefixed leftovers that reference each other (circular FK), so
        // that — without disabling FK checks — neither can be dropped first
        // regardless of the order ensureTablePrefix() iterates metadata in. This
        // makes the regression deterministic: on the unfixed code the drop
        // raises 1451 every run, not just sometimes.
        $pdo->exec('CREATE TABLE tngtest_resource_entity (id INT AUTO_INCREMENT NOT NULL, item_id INT DEFAULT NULL, INDEX idx_item (item_id), PRIMARY KEY(id)) ENGINE=InnoDB');
        $pdo->exec(
            'CREATE TABLE tngtest_item_entity ('
            .'id INT AUTO_INCREMENT NOT NULL, resource_id INT DEFAULT NULL, '
            .'INDEX idx_resource (resource_id), '
            .'CONSTRAINT fk_tngtest_item_resource FOREIGN KEY (resource_id) REFERENCES tngtest_resource_entity (id), '
            .'PRIMARY KEY(id)) ENGINE=InnoDB'
        );
        $pdo->exec('ALTER TABLE tngtest_resource_entity ADD CONSTRAINT fk_tngtest_resource_item FOREIGN KEY (item_id) REFERENCES tngtest_item_entity (id)');

        // Live (prefixed) counterparts exist too, so ensureTablePrefix() takes
        // the "both exist → drop the unprefixed duplicate" branch.
        $pdo->exec('CREATE TABLE wp_tngtest_resource_entity (id INT AUTO_INCREMENT NOT NULL, PRIMARY KEY(id)) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE wp_tngtest_item_entity (id INT AUTO_INCREMENT NOT NULL, PRIMARY KEY(id)) ENGINE=InnoDB');

        // Does not throw 1451.
        $this->invokePrivate($this->makeRunner(), 'ensureTablePrefix');

        self::assertFalse($this->tableExists('tngtest_resource_entity'), 'bare parent table should be dropped');
        self::assertFalse($this->tableExists('tngtest_item_entity'), 'bare child table should be dropped');
        self::assertTrue($this->tableExists('wp_tngtest_resource_entity'), 'prefixed parent table should remain');
        self::assertTrue($this->tableExists('wp_tngtest_item_entity'), 'prefixed child table should remain');
    }

    /**
     * A migration whose first statement already ran (its table exists) but
     * whose later statements did not must be completed on replay rather than
     * marked done with the remainder silently skipped.
     */
    public function testPartiallyAppliedMigrationIsCompletedOnReplay(): void {
        // First of the migration's two tables already exists → "already exists".
        $this->pdo()->exec('CREATE TABLE wp_tngtest_replay_a (id INT AUTO_INCREMENT NOT NULL, PRIMARY KEY(id)) ENGINE=InnoDB');

        $result = $this->makeRunner()->runPendingMigrations();

        self::assertTrue($result['success'], 'run should succeed despite the pre-existing table');
        self::assertTrue($this->tableExists('wp_tngtest_replay_a'), 'pre-existing table is preserved');
        self::assertTrue($this->tableExists('wp_tngtest_replay_b'), 'the outstanding statement is applied on replay');

        $recorded = (int) $this->pdo()
            ->query("SELECT COUNT(*) FROM doctrine_migration_versions WHERE version LIKE '%Version29990101000000'")
            ->fetchColumn();
        self::assertSame(1, $recorded, 'the migration is recorded as executed exactly once');
    }

    private function makeRunner(): MigrationRunner {
        \assert($this->em !== null);

        return new MigrationRunner(
            $this->em,
            __DIR__.'/Fixtures/migrations.php',
            self::PLUGIN_SLUG,
        );
    }

    private function makeEntityManager(): EntityManager {
        $host = getenv('WORDPRESS_DB_HOST') ?: 'db';
        $port = (int) (getenv('WORDPRESS_DB_PORT') ?: 3306);
        $user = getenv('DOCTRINE_TEST_DB_USER') ?: 'root';

        $password = getenv('DOCTRINE_TEST_DB_PASSWORD');
        if ($password === false) {
            $password = getenv('WORDPRESS_DB_ROOT_PASSWORD') ?: '';
        }

        $proxyDir = sys_get_temp_dir().'/tngtest_doctrine_proxies';
        if (!is_dir($proxyDir)) {
            @mkdir($proxyDir, 0o777, true);
        }

        try {
            $em = EntityManagerFactory::create([
                'dev_mode' => true,
                'auto_create_database' => true,
                'dev_connection' => [
                    'driver' => 'pdo_mysql',
                    'host' => $host,
                    'port' => $port,
                    'dbname' => 'tangible_doctrine_test',
                    'user' => $user,
                    'password' => $password,
                    'charset' => 'utf8mb4',
                ],
                'entity_paths' => [__DIR__.'/Fixtures/Entity'],
                'plugin_slug' => self::PLUGIN_SLUG,
                'table_prefix' => 'wp_',
                'proxy_dir' => $proxyDir,
            ]);

            // Force a real connection so the test can skip rather than error
            // when no database server is available.
            $em->getConnection()->executeQuery('SELECT 1');

            return $em;
        } catch (\Throwable $e) {
            self::markTestSkipped('MySQL/MariaDB not reachable for integration test: '.$e->getMessage());
        }
    }

    private function pdo(): \PDO {
        \assert($this->em !== null);
        /** @var \PDO $pdo */
        $pdo = $this->em->getConnection()->getNativeConnection();

        return $pdo;
    }

    private function tableExists(string $table): bool {
        $stmt = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$table]);

        return (bool) $stmt->fetchColumn();
    }

    private function cleanupTables(): void {
        $pdo = $this->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach ([
            self::BARE_PREFIX.'item_entity',
            self::BARE_PREFIX.'resource_entity',
            self::FULL_PREFIX.'item_entity',
            self::FULL_PREFIX.'resource_entity',
            self::FULL_PREFIX.'replay_a',
            self::FULL_PREFIX.'replay_b',
            'doctrine_migration_versions',
        ] as $table) {
            $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function invokePrivate(object $object, string $method, mixed ...$args): mixed {
        $ref = new \ReflectionMethod($object, $method);
        $ref->setAccessible(true);

        return $ref->invoke($object, ...$args);
    }
}
