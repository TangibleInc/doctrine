<?php declare( strict_types=1 );

/**
 * File: src/Database/WordPressQueryLogger.php
 *
 * Doctrine Middleware that logs queries to WordPress's $wpdb->queries array
 * This allows debugging plugins like Query Monitor to display Doctrine queries
 */

namespace Tangible\Doctrine\Database;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;
use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Doctrine\DBAL\ParameterType;

/**
 * Middleware that logs all Doctrine queries to WordPress's query log
 */
class WordPressQueryLogger implements MiddlewareInterface
{
  public function wrap( DriverInterface $driver ): DriverInterface
  {
    return new class( $driver ) implements DriverInterface {
      private DriverInterface $driver;

      public function __construct( DriverInterface $driver )
      {
        $this->driver = $driver;
      }

      public function connect( #[\SensitiveParameter] array $params ): ConnectionInterface
      {
        return new class( $this->driver->connect( $params ) ) implements ConnectionInterface {
          private ConnectionInterface $connection;

          public function __construct( ConnectionInterface $connection )
          {
            $this->connection = $connection;
          }

          public function prepare( string $sql ): StatementInterface
          {
            return new class( $this->connection->prepare( $sql ), $sql ) implements StatementInterface {
              private StatementInterface $statement;
              private string $sql;
              private float $startTime;

              public function __construct( StatementInterface $statement, string $sql )
              {
                $this->statement = $statement;
                $this->sql = $sql;
              }

              public function bindValue( $param, $value, $type = null ): void
              {
                $this->statement->bindValue( $param, $value, $type );
              }

              public function bindParam( $param, &$variable, $type = null, $length = null ): void
              {
                $this->statement->bindParam( $param, $variable, $type, $length );
              }

              public function execute( $params = null ): Result
              {
                $this->startTime = microtime( true );

                try {
                  $result = $this->statement->execute( $params );
                  $this->logQuery( null );
                  return $result;
                } catch ( \Throwable $e ) {
                  $this->logQuery( $e );
                  throw $e;
                }
              }

              private function logQuery( ?\Throwable $exception ): void
              {
                global $wpdb;

                // Only log if SAVEQUERIES is enabled
                if ( ! defined( 'SAVEQUERIES' ) || ! SAVEQUERIES ) {
                  return;
                }

                // Calculate query time
                $queryTime = microtime( true ) - $this->startTime;

                // Get the stack trace to show where the query was called from
                $backtrace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );

                // Find the first non-Doctrine call in the stack
                $caller = 'Unknown';
                foreach ( $backtrace as $trace ) {
                  if ( isset( $trace['file'] ) &&
                       ! str_contains( $trace['file'], 'vendor/doctrine' ) &&
                       ! str_contains( $trace['file'], 'src/Database' ) ) {
                    $caller = ( $trace['file'] ?? '' ) . ':' . ( $trace['line'] ?? '0' );
                    break;
                  }
                }

                // Add error information if query failed
                $errorInfo = null;
                if ( $exception ) {
                  $errorInfo = $exception->getMessage();
                }

                // Format the query entry to match WordPress's format:
                // [ query, execution_time, caller, start_time, custom_data ]
                $queryEntry = [
                  $this->sql,
                  $queryTime,
                  $caller,
                  $this->startTime,
                  [
                    'source' => 'doctrine',
                    'error' => $errorInfo,
                  ]
                ];

                // Add to WordPress query log
                if ( ! is_array( $wpdb->queries ) ) {
                  $wpdb->queries = [];
                }

                $wpdb->queries[] = $queryEntry;
              }
            };
          }

          public function query( string $sql ): Result
          {
            global $wpdb;

            $startTime = microtime( true );

            try {
              $result = $this->connection->query( $sql );

              // Log the query
              if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
                $queryTime = microtime( true ) - $startTime;

                $backtrace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
                $caller = 'Unknown';
                foreach ( $backtrace as $trace ) {
                  if ( isset( $trace['file'] ) &&
                       ! str_contains( $trace['file'], 'vendor/doctrine' ) &&
                       ! str_contains( $trace['file'], 'src/Database' ) ) {
                    $caller = ( $trace['file'] ?? '' ) . ':' . ( $trace['line'] ?? '0' );
                    break;
                  }
                }

                $wpdb->log_query(
                  $sql,
                  $queryTime,
                  $caller,
                  $startTime,
                  [ 'source' => 'doctrine' ]
                );
              }

              return $result;
            } catch ( \Throwable $e ) {
              // Log failed query
              if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
                $queryTime = microtime( true ) - $startTime;

                $wpdb->log_query(
                  $sql,
                  $queryTime,
                  'doctrine-error',
                  $startTime,
                  [
                    'source' => 'doctrine',
                    'error' => $e->getMessage()
                  ]
                );
              }

              throw $e;
            }
          }

          public function exec( string $sql ): int
          {
            return $this->connection->exec( $sql );
          }

          public function lastInsertId( $name = null )
          {
            return $this->connection->lastInsertId( $name );
          }

          public function beginTransaction(): void
          {
            $this->connection->beginTransaction();
          }

          public function commit(): void
          {
            $this->connection->commit();
          }

          public function rollBack(): void
          {
            $this->connection->rollBack();
          }

          public function getNativeConnection(): mixed
          {
            return $this->connection->getNativeConnection();
          }

          public function getServerVersion(): string
          {
            return $this->connection->getServerVersion();
          }

          public function quote($value, $type = ParameterType::STRING)
          {
            return $this->connection->quote($value, $type);
          }
        };
      }

      public function getDatabasePlatform() {
        return $this->driver->getDatabasePlatform();
      }

      public function getSchemaManager(
        \Doctrine\DBAL\Connection $connection,
        \Doctrine\DBAL\Platforms\AbstractPlatform $platform
      ) {
        return $this->driver->getSchemaManager( $connection, $platform );
      }

      public function getExceptionConverter(): \Doctrine\DBAL\Driver\API\ExceptionConverter
      {
        return $this->driver->getExceptionConverter();
      }
    };
  }
}
