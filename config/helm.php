<?php

/**
 * Default configuration for PressGang Helm.
 *
 * These values are used as fallback defaults when no explicit
 * configuration is provided via constants, environment, or options.
 */
return [
    'provider' => 'openai',
    'model' => 'gpt-4o',
    'api_key' => '',
    'temperature' => 1.0,
    'timeout' => 30,
    'retries' => 1,
    'logging' => false,
];
