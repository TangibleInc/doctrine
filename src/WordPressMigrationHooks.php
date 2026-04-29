<?php

declare(strict_types=1);

namespace Tangible\Doctrine;

/**
 * Helper functions for setting up WordPress migration hooks.
 *
 * Usage in plugin main file:
 *
 * ```php
 * use Tangible\Doctrine\EntityManagerFactory;
 * use Tangible\Doctrine\MigrationRunner;
 * use function Tangible\Doctrine\register_migration_hooks;
 *
 * register_migration_hooks(
 *     pluginFile: __FILE__,
 *     pluginSlug: 'tangible_lms',
 *     version: TANGIBLE_LMS_VERSION,
 *     entityPaths: [__DIR__ . '/src/Infrastructure/Persistence/Doctrine/Entity'],
 *     migrationsConfigPath: __DIR__ . '/migrations.php',
 * );
 * ```
 */

/**
 * Register WordPress hooks for automatic migration execution.
 *
 * @param string $pluginFile Path to main plugin file (__FILE__)
 * @param string $pluginSlug Plugin slug for option names and table prefixes
 * @param string $version Current plugin version
 * @param string[] $entityPaths Paths to Doctrine entity directories
 * @param string $migrationsConfigPath Path to migrations.php config file
 */
function register_migration_hooks(
    string $pluginFile,
    string $pluginSlug,
    string $version,
    array $entityPaths,
    string $migrationsConfigPath,
): void {
    $runMigrations = static function () use ($pluginSlug, $entityPaths, $migrationsConfigPath): array {
        global $wpdb;

        $em = EntityManagerFactory::getInstance([
            'entity_paths' => $entityPaths,
            'plugin_slug' => $pluginSlug,
            'table_prefix' => $wpdb->prefix,
        ]);

        $runner = new MigrationRunner($em, $migrationsConfigPath, $pluginSlug);

        return $runner->runPendingMigrations();
    };

    // Run on plugin activation
    register_activation_hook($pluginFile, $runMigrations);

    // Run on version update
    add_action('plugins_loaded', static function () use ($pluginSlug, $entityPaths, $version, $runMigrations): void {
        $storedVersion = get_option($pluginSlug.'_version');

        if (version_compare($version, $storedVersion ?: '0.0.0', 'gt')) {
            // Clear stale metadata cache before running migrations
            EntityManagerFactory::clearMetadataCache([
                'entity_paths' => $entityPaths,
                'plugin_slug' => $pluginSlug,
            ]);

            $result = $runMigrations();

            // Only store version if migrations succeeded, so they retry on next load
            if ($result['success']) {
                update_option($pluginSlug.'_version', $version, true);
            }
        }
    }, 5); // Priority 5 to run before most other plugins_loaded hooks
}

/**
 * Create a MigrationRunner instance for manual migration control.
 *
 * @param string $pluginSlug Plugin slug for option names and table prefixes
 * @param string[] $entityPaths Paths to Doctrine entity directories
 * @param string $migrationsConfigPath Path to migrations.php config file
 */
function create_migration_runner(
    string $pluginSlug,
    array $entityPaths,
    string $migrationsConfigPath,
): MigrationRunner {
    global $wpdb;

    $em = EntityManagerFactory::getInstance([
        'entity_paths' => $entityPaths,
        'plugin_slug' => $pluginSlug,
        'table_prefix' => $wpdb->prefix,
    ]);

    return new MigrationRunner($em, $migrationsConfigPath, $pluginSlug);
}
