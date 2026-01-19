<?php
/**
 * Phan configuration for DDD package.
 *
 * @package tangible/ddd
 */

// Load the shared config builder
require_once __DIR__ . '/../../../.phan/config.php';

// Generate config for this project
return make_phan_config(
    __DIR__ . '/..',
    array(
        'directory_list'                  => array(
            'src',
            'vendor',
        ),
        'exclude_analysis_directory_list' => array(
            'vendor/',
        ),
        '+stubs' => array( 'redis' )
    )
);
