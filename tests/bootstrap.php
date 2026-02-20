<?php

/**
 * PHPUnit bootstrap.
 *
 * Loads WordPress function stubs (for WP adapter tests) then Composer autoload.
 */

require_once __DIR__ . '/stubs/wordpress.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';
