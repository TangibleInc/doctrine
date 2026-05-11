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
    private static ?EntityManager $instance = null;
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

        $connectionConfig = $connection->getConfiguration();
        $connectionConfig->setSchemaAssetsFilter(static function ($asset) use ($naming_strategy_prefix) {
            return str_starts_with($asset, $naming_strategy_prefix) || $asset === 'doctrine_migration_versions';
        });

        return new EntityManager($connection, $doctrineConfig);
    }

    public static function getInstance(array $config = []): EntityManager {
        if (self::$instance === null) {
            self::$instance = self::create($config);
        }

        return self::$instance;
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
        self::$instance = null;
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

        // Reset the singleton so the next getInstance() rebuilds with fresh metadata
        self::reset();
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
