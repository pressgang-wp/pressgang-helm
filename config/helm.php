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
    'fallback_providers' => [],
    'tools' => [],
    'openai' => [
        'base_url' => 'https://api.openai.com/v1',
    ],
    'anthropic' => [
        'base_url' => 'https://api.anthropic.com/v1',
        'max_tokens' => 4096,
        'api_version' => '2023-06-01',
    ],
];
