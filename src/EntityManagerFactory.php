<?php

declare(strict_types=1);

/**
 * File: src/Database/EntityManagerFactory.php.
 *
 * Factory for creating Doctrine EntityManager instances
 */

namespace Tangible\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Proxy\ProxyFactory;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Serializer;

class EntityManagerFactory {
    /** @var array<string, EntityManager> EntityManagers keyed by plugin_slug. */
    private static array $instances = [];

    /** Slug of the first-seeded instance — the default for no-arg getInstance(). */
    private static ?string $defaultSlug = null;

    private static ?Serializer $serializer = null;

    public static function create(array $config = []): EntityManager {
        // Determine if we're in development mode
        $isDev = self::isDevelopmentMode($config);

        // Get database configuration
        $dbConfig = self::getDatabaseConfig($config, $isDev);

        // Auto-create database if it doesn't exist (enabled by default in dev mode)
        $autoCreateDb = $config['auto_create_database'] ?? $isDev;
        if ($autoCreateDb) {
            self::ensureDatabaseExists($dbConfig['connection']);
        }

        // Setup Doctrine
        $paths = $config['entity_paths'] ?? [__DIR__.'/../Entity/'];
        $isDevMode = $dbConfig['dev_mode'] ?? false;
        $plugin_slug = $config['plugin_slug'] ?? 'tangible';
        $table_prefix = $config['table_prefix'] ?? '';

        // Set proxy directory - use a writable location
        $proxyDir = $config['proxy_dir'] ?? self::resolveProxyDir($plugin_slug);

        $cache = null;
        if (\defined('WP_REDIS_USER_SESSION_HOST') && \extension_loaded('redis')) {
            $redis = new \Redis();
            $redis->connect((string) WP_REDIS_USER_SESSION_HOST);

            $redis_namespace = $config['redis_namespace'] ?? 'dc2_'.$plugin_slug;
            $cache = new RedisAdapter($redis, $redis_namespace);
        }

        $mappingType = $config['mapping_type'] ?? 'attribute';

        if ($mappingType === 'xml') {
            $doctrineConfig = ORMSetup::createXMLMetadataConfiguration(
                $paths,
                $isDevMode,
                $proxyDir,
                $cache
            );
        } else {
            $doctrineConfig = ORMSetup::createAttributeMetadataConfiguration(
                $paths,
                $isDevMode,
                $proxyDir,
                $cache
            );
        }

        // In dev/tests, regenerate proxies only when the file is missing
        // or the entity has changed — not on every request. This keeps
        // dev ergonomics (entity edits picked up automatically) while
        // not requiring the proxy directory to be writable when valid
        // pre-generated proxies are already present (e.g. CI runs that
        // generate proxies on the host before mounting the volume into
        // a container running as a different user).
        if ($isDevMode || (\defined('WP_TESTS_DIR') && WP_TESTS_DIR)) {
            $doctrineConfig->setAutoGenerateProxyClasses(
                ProxyFactory::AUTOGENERATE_FILE_NOT_EXISTS_OR_CHANGED,
            );
        }

        // Set naming strategy
        // $table_prefix comes from $wpdb->prefix which already includes a trailing underscore (e.g. "wp_")
        $naming_strategy_prefix = $plugin_slug.'_';
        if (!empty($table_prefix)) {
            $naming_strategy_prefix = $table_prefix.$naming_strategy_prefix;
        }
        $doctrineConfig->setNamingStrategy(new NamingStrategy($naming_strategy_prefix));

        // Middlewares: table prefix rewriter (always) + query logger (dev only)
        $barePrefix = $plugin_slug.'_';
        $middlewares = [
            new TablePrefixMiddleware($barePrefix, $naming_strategy_prefix),
        ];

        if (\defined('SAVEQUERIES') && SAVEQUERIES) {
            $middlewares[] = new WordPressQueryLogger();
        }

        $doctrineConfig->setMiddlewares($middlewares);

        // Create connection
        $connection = DriverManager::getConnection($dbConfig['connection'], $doctrineConfig);

        // Tables in 'excluded_tables' share our prefix but are owned by another
        // component (e.g. the tangible-ddd framework installs its own tables via
        // raw SQL). Hide them from schema introspection so migration:diff does
        // not emit DROP TABLE for unmapped tables.
        $excluded_tables = $config['excluded_tables'] ?? [];
        $excluded_full_names = array_map(
            static fn (string $bare_name): string => $naming_strategy_prefix.$bare_name,
            $excluded_tables,
        );

        $connectionConfig = $connection->getConfiguration();
        $connectionConfig->setSchemaAssetsFilter(static function ($asset) use ($naming_strategy_prefix, $excluded_full_names) {
            if ($asset === 'doctrine_migration_versions') {
                return true;
            }

            if (!str_starts_with($asset, $naming_strategy_prefix)) {
                return false;
            }

            return !\in_array($asset, $excluded_full_names, true);
        });

        return new EntityManager($connection, $doctrineConfig);
    }

    /**
     * Get (or lazily create) the EntityManager for a plugin, keyed by
     * `plugin_slug`. Multiple Doctrine-using plugins coexist in one WordPress
     * process, so a single shared instance would make the second plugin query
     * the first plugin's tables. Each plugin seeds its own instance at
     * plugins_loaded by calling this with its config (incl. plugin_slug).
     *
     * A no-arg call returns the first-seeded ("default") instance, preserving
     * the previous single-instance behaviour for callers without plugin context.
     */
    public static function getInstance(array $config = []): EntityManager {
        $slug = $config['plugin_slug'] ?? self::$defaultSlug ?? 'tangible';

        if (!isset(self::$instances[$slug])) {
            self::$instances[$slug] = self::create($config);
            self::$defaultSlug ??= $slug;
        }

        return self::$instances[$slug];
    }

    /**
     * Retrieve the already-seeded EntityManager for a plugin by slug. Used by
     * each plugin's DI container to inject the correct EntityManager into its
     * IDoctrineAware repositories (the slug is a compile-safe constant — no
     * runtime paths are baked into a compiled container).
     */
    public static function getInstanceForPlugin(string $plugin_slug): EntityManager {
        if (isset(self::$instances[$plugin_slug])) {
            return self::$instances[$plugin_slug];
        }

        // Not seeded under this slug. With two or more EntityManagers active,
        // the slug is genuinely wrong and guessing would query another plugin's
        // tables — fail loudly. With zero or one seeded there is no ambiguity:
        // a single-Doctrine-plugin runtime, the container smoke test (seeds
        // none, never queries), or integration tests that seed under a *_test
        // slug. Fall back to the default/lazy instance.
        if (\count(self::$instances) >= 2) {
            throw new \RuntimeException(\sprintf('No EntityManager seeded for plugin "%s" while %d are active; refusing to guess. Ensure the plugin seeds its EntityManager (EntityManagerFactory::getInstance with plugin_slug) on plugins_loaded.', $plugin_slug, \count(self::$instances)));
        }

        return self::getInstance();
    }

    public static function getSerializer(): Serializer {
        if (self::$serializer === null) {
            $encoders = [new JsonEncoder()];
            $normalizers = [
                new ArrayDenormalizer(),
                new SnakeCaseObjectNormalizer(),
            ];
            self::$serializer = new Serializer($normalizers, $encoders);
        }

        return self::$serializer;
    }

    private static function isDevelopmentMode(array $config): bool {
        // Check explicit config first
        if (isset($config['dev_mode'])) {
            return (bool) $config['dev_mode'];
        }

        // Check if CLI mode (for doctrine commands)
        if (\PHP_SAPI === 'cli') {
            return true;
        }

        // Check WordPress debug constant
        if (\defined('WP_DEBUG') && WP_DEBUG) {
            return true;
        }

        return false;
    }

    private static function getDatabaseConfig(array $config, bool $isDev): array {
        if ($isDev && isset($config['dev_connection'])) {
            // Use development database configuration
            return [
                'connection' => $config['dev_connection'],
                'dev_mode' => true,
            ];
        }

        // Use WordPress database configuration
        return [
            'connection' => [
                'driver' => 'pdo_mysql',
                'host' => \defined('DB_HOST') ? DB_HOST : 'localhost',
                'dbname' => \defined('DB_NAME') ? DB_NAME : '',
                'user' => \defined('DB_USER') ? DB_USER : '',
                'password' => \defined('DB_PASSWORD') ? DB_PASSWORD : '',
                'charset' => \defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4',
            ],
            'dev_mode' => $isDev,  // Use the detected dev mode, not hardcoded false
        ];
    }

    public static function reset(): void {
        self::$instances = [];
        self::$defaultSlug = null;
    }

    /**
     * Evict a single plugin's EntityManager so its next getInstance()
     * rebuilds with fresh metadata — WITHOUT touching the other plugins'
     * instances. A global reset() here once let one plugin's version-bump
     * migration wipe every seeded instance mid-boot; the single survivor
     * re-seeded was then served to the other plugin by the
     * getInstanceForPlugin() single-instance fallback, which queried the
     * wrong table prefix.
     */
    public static function resetForPlugin(string $plugin_slug): void {
        unset(self::$instances[$plugin_slug]);

        if (self::$defaultSlug === $plugin_slug) {
            self::$defaultSlug = array_key_first(self::$instances);
        }
    }

    /**
     * Clear the Doctrine metadata cache.
     *
     * Should be called on plugin version updates before creating a new
     * EntityManager, so that stale entity mappings don't cause errors.
     */
    public static function clearMetadataCache(array $config = []): void {
        $plugin_slug = $config['plugin_slug'] ?? 'tangible';

        if (\defined('WP_REDIS_USER_SESSION_HOST') && \extension_loaded('redis')) {
            try {
                $redis = new \Redis();
                $redis->connect((string) WP_REDIS_USER_SESSION_HOST);

                $redis_namespace = $config['redis_namespace'] ?? 'dc2_'.$plugin_slug;
                $cache = new RedisAdapter($redis, $redis_namespace);
                $cache->clear();
            } catch (\Exception $e) {
                if (\function_exists('error_log')) {
                    error_log('[Tangible\\Doctrine] Could not clear metadata cache: '.$e->getMessage());
                }
            }
        }

        // Evict only this plugin's instance so its next getInstance()
        // rebuilds with fresh metadata; other plugins keep theirs.
        self::resetForPlugin($plugin_slug);
    }

    /**
     * Resolve a writable proxy directory, trying candidates in order:
     * 1. WP_CONTENT_DIR/cache/{slug}/doctrine_proxies (reliable on all WP hosts)
     * 2. System temp directory (always writable, but ephemeral)
     */
    private static function resolveProxyDir(string $pluginSlug): string {
        $candidates = [];

        if (\defined('WP_CONTENT_DIR')) {
            $candidates[] = WP_CONTENT_DIR.'/cache/'.$pluginSlug.'/doctrine_proxies';
        }

        $candidates[] = sys_get_temp_dir().'/'.$pluginSlug.'_doctrine_proxies';

        foreach ($candidates as $dir) {
            if (is_dir($dir) && is_writable($dir)) {
                return $dir;
            }

            if (@mkdir($dir, 0755, true) && is_writable($dir)) {
                return $dir;
            }
        }

        // Last resort — sys_get_temp_dir() should always work,
        // but if mkdir failed above, return it anyway and let Doctrine report the error.
        return end($candidates);
    }

    /**
     * Ensure the database exists, creating it if necessary.
     *
     * Connects to MySQL without specifying a database and runs
     * CREATE DATABASE IF NOT EXISTS.
     *
     * @param array{driver?: string, host?: string, port?: int, user?: string, password?: string, dbname?: string, charset?: string} $connectionParams
     */
    private static function ensureDatabaseExists(array $connectionParams): void {
        $dbName = $connectionParams['dbname'] ?? '';
        if ($dbName === '') {
            return;
        }

        // Connect without database to create it
        $tmpParams = $connectionParams;
        unset($tmpParams['dbname']);

        try {
            $tmpConnection = DriverManager::getConnection($tmpParams);
            $schemaManager = $tmpConnection->createSchemaManager();
            $databases = $schemaManager->listDatabases();

            if (!\in_array($dbName, $databases, true)) {
                $schemaManager->createDatabase($dbName);
            }

            $tmpConnection->close();
        } catch (\Exception $e) {
            // Log but don't fail - the database might already exist
            // or we might not have CREATE DATABASE privileges
            if (\function_exists('error_log')) {
                error_log('[Tangible\\Doctrine] Could not ensure database exists: '.$e->getMessage());
            }
        }
    }
}
