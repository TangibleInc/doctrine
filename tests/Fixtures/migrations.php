<?php

declare(strict_types=1);

return [
    'table_storage' => [
        'table_name' => 'doctrine_migration_versions',
        'version_column_name' => 'version',
        'version_column_length' => 191,
        'executed_at_column_name' => 'executed_at',
        'execution_time_column_name' => 'execution_time',
    ],
    'migrations_paths' => [
        'Tangible\\Doctrine\\Tests\\Fixtures\\Migrations' => __DIR__.'/Migrations',
    ],
    'all_or_nothing' => false,
    'transactional' => true,
    'check_database_platform' => false,
    'organize_migrations' => 'none',
];
