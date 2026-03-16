<?php

declare(strict_types=1);

namespace Tangible\Doctrine;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Doctrine\DBAL\ParameterType;

/**
 * DBAL middleware that rewrites SQL table references from bare plugin prefix
 * to the full WordPress-prefixed table names.
 *
 * Migration diffs are generated against the CLI database (bare prefix, e.g.
 * `tangible_lms_`), but in WordPress the tables have the WP prefix prepended
 * (e.g. `wp_tangible_lms_`). This middleware transparently rewrites all SQL
 * so that migrations work in both environments without manual edits.
 */
class TablePrefixMiddleware implements MiddlewareInterface {
    private string $pattern;

    /**
     * @param string $barePrefix The plugin-only prefix (e.g. "tangible_lms_")
     * @param string $fullPrefix The full prefix including WP prefix (e.g. "wp_tangible_lms_")
     */
    public function __construct(
        private string $barePrefix,
        private string $fullPrefix,
    ) {
        // Build a regex that matches the bare prefix only when NOT already
        // preceded by the WP part. e.g. for wp_tangible_lms_, the WP part is "wp_".
        $wpPart = substr($this->fullPrefix, 0, \strlen($this->fullPrefix) - \strlen($this->barePrefix));
        $this->pattern = '/(?<!'.preg_quote($wpPart, '/').')'.preg_quote($this->barePrefix, '/').'/';
    }

    public function wrap(DriverInterface $driver): DriverInterface {
        if ($this->barePrefix === $this->fullPrefix) {
            return $driver;
        }

        return new TablePrefixDriver($driver, $this->pattern, $this->fullPrefix);
    }
}

/**
 * @internal
 */
class TablePrefixDriver implements DriverInterface {
    public function __construct(
        private DriverInterface $driver,
        private string $pattern,
        private string $fullPrefix,
    ) {
    }

    public function connect(#[\SensitiveParameter] array $params): ConnectionInterface {
        return new TablePrefixConnection(
            $this->driver->connect($params),
            $this->pattern,
            $this->fullPrefix,
        );
    }

    public function getDatabasePlatform(): \Doctrine\DBAL\Platforms\AbstractPlatform {
        return $this->driver->getDatabasePlatform();
    }

    /** @suppress PhanDeprecatedFunction */
    public function getSchemaManager(
        \Doctrine\DBAL\Connection $connection,
        \Doctrine\DBAL\Platforms\AbstractPlatform $platform
    ): \Doctrine\DBAL\Schema\AbstractSchemaManager {
        return $this->driver->getSchemaManager($connection, $platform);
    }

    public function getExceptionConverter(): DriverInterface\API\ExceptionConverter {
        return $this->driver->getExceptionConverter();
    }
}

/**
 * @internal
 */
class TablePrefixConnection implements ConnectionInterface {
    public function __construct(
        private ConnectionInterface $connection,
        private string $pattern,
        private string $fullPrefix,
    ) {
    }

    public function prepare(string $sql): StatementInterface {
        return $this->connection->prepare($this->rewrite($sql));
    }

    public function query(string $sql): Result {
        return $this->connection->query($this->rewrite($sql));
    }

    public function exec(string $sql): int {
        return $this->connection->exec($this->rewrite($sql));
    }

    public function lastInsertId($name = null) {
        return $this->connection->lastInsertId($name);
    }

    public function beginTransaction(): bool {
        return $this->connection->beginTransaction();
    }

    public function commit(): bool {
        return $this->connection->commit();
    }

    public function rollBack(): bool {
        return $this->connection->rollBack();
    }

    public function getNativeConnection(): mixed {
        return $this->connection->getNativeConnection();
    }

    public function quote($value, $type = ParameterType::STRING) {
        return $this->connection->quote($value, $type);
    }

    private function rewrite(string $sql): string {
        return preg_replace($this->pattern, $this->fullPrefix, $sql);
    }
}
