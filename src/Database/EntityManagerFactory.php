<?php declare( strict_types=1 );

/**
 * File: src/Database/EntityManagerFactory.php
 *
 * Factory for creating Doctrine EntityManager instances
 */

namespace Tangible\Doctrine\Database;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Serializer;

use Tangible\Doctrine\Database\NamingStrategy;
use Tangible\Doctrine\Database\SnakeCaseObjectNormalizer;
use Tangible\Doctrine\Database\WordPressQueryLogger;
use Symfony\Component\Cache\Adapter\RedisAdapter;

class EntityManagerFactory {
  private static ?EntityManager $instance = null;
  private static ?Serializer $serializer = null;

  public static function create( array $config = [] ): EntityManager
  {
    // Determine if we're in development mode
    $isDev = self::isDevelopmentMode( $config );

    // Get database configuration
    $dbConfig = self::getDatabaseConfig( $config, $isDev );

    // Setup Doctrine
    $paths = $config['entity_paths'] ?? [ __DIR__ . '/../Entity/' ];
    $isDevMode = $dbConfig['dev_mode'] ?? false;
    $plugin_slug = isset($config['plugin_slug']) ? $config['plugin_slug'] : 'tangible';

    // Set proxy directory - use a writable location
    $defaultProxyDir = __DIR__ . '/../../var/doctrine_proxies';
    $proxyDir = $config['proxy_dir'] ?? $defaultProxyDir;

    // Ensure proxy directory exists and is writable
    if ( ! is_dir( $proxyDir ) ) {
      if ( ! @mkdir( $proxyDir, 0755, true ) ) {
        // Fallback to temp dir if we can't create in var/
        $proxyDir = sys_get_temp_dir() . '/' . $plugin_slug . '_doctrine_proxies';
        @mkdir( $proxyDir, 0755, true );
      }
    }

    // Ensure writable
    if ( ! is_writable( $proxyDir ) ) {
      @chmod( $proxyDir, 0755 );
    }

    $cache = null;
    if ( defined( 'WP_REDIS_USER_SESSION_HOST' ) && extension_loaded( 'redis' ) ) {
      $redis = new \Redis();
      $redis->connect( WP_REDIS_USER_SESSION_HOST );

      $redis_namespace = isset($config['redis_namespace']) ? $config['redis_namespace'] : 'dc2_' . $plugin_slug;
      $cache = new RedisAdapter( $redis, $redis_namespace ); 
    }

    $doctrineConfig = ORMSetup::createAttributeMetadataConfiguration(
      $paths,
      $isDevMode,
      $proxyDir,
      $cache
    );

    // Enable proxy auto-generation in dev mode and tests
    if ( $isDevMode || ( defined( 'WP_TESTS_DIR' ) && WP_TESTS_DIR ) ) {
      $doctrineConfig->setAutoGenerateProxyClasses( true );
    }

    // Set naming strategy
    $naming_strategy_prefix = $plugin_slug . '_';
    $doctrineConfig->setNamingStrategy( new NamingStrategy( $naming_strategy_prefix ) );

    // Add WordPress query logger middleware if SAVEQUERIES is enabled
    if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
      $doctrineConfig->setMiddlewares([
        new WordPressQueryLogger()
      ]);
    }

    // Create connection
    $connection = DriverManager::getConnection( $dbConfig['connection'], $doctrineConfig );

    $connectionConfig = $connection->getConfiguration();
    $connectionConfig->setSchemaAssetsFilter( function( $asset ) {
      return str_starts_with( $asset, $naming_strategy_prefix ) || $asset === 'doctrine_migration_versions';
    });

    return new EntityManager( $connection, $doctrineConfig );
  }

  public static function getInstance( array $config = [] ): EntityManager
  {
    if ( self::$instance === null ) {
      self::$instance = self::create( $config );
    }

    return self::$instance;
  }

  public static function getSerializer(): Serializer
  {
    if ( self::$serializer === null ) {
      $encoders         = [ new JsonEncoder() ];
      $normalizers      = [
        new ArrayDenormalizer(),
        new SnakeCaseObjectNormalizer()
      ];
      self::$serializer = new Serializer( $normalizers, $encoders );
    }

    return self::$serializer;
  }

  private static function isDevelopmentMode( array $config ): bool
  {
    // Check explicit config first
    if ( isset( $config['dev_mode'] ) ) {
      return (bool) $config['dev_mode'];
    }

    // Check if CLI mode (for doctrine commands)
    if ( php_sapi_name() === 'cli' ) {
      return true;
    }

    // Check WordPress debug constant
    if ( defined('WP_DEBUG') && WP_DEBUG ) {
      return true;
    }

    return false;
  }

  private static function getDatabaseConfig( array $config, bool $isDev ): array
  {
    if ( $isDev && isset( $config['dev_connection'] ) ) {
      // Use development database configuration
      return [
        'connection' => $config['dev_connection'],
        'dev_mode' => true
      ];
    }

    // Use WordPress database configuration
    return [
      'connection' => [
        'driver' => 'pdo_mysql',
        'host' => defined('DB_HOST') ? DB_HOST : 'localhost',
        'dbname' => defined('DB_NAME') ? DB_NAME : '',
        'user' => defined('DB_USER') ? DB_USER : '',
        'password' => defined('DB_PASSWORD') ? DB_PASSWORD : '',
        'charset' => defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4',
      ],
      'dev_mode' => $isDev  // Use the detected dev mode, not hardcoded false
    ];
  }

  public static function reset(): void
  {
    self::$instance = null;
  }
}
