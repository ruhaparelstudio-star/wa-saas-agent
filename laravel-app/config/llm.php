<?php

return [
    'provider'         => env('LLM_PROVIDER', 'openai'),
    'classifier_model' => env('LLM_CLASSIFIER_MODEL', 'gpt-4o-mini'),
    'composer_model'   => env('LLM_COMPOSER_MODEL', 'gpt-4o'),
    'embedding_model'  => env('LLM_EMBEDDING_MODEL', 'text-embedding-3-small'),
    'timeout_seconds'  => (int) env('LLM_TIMEOUT_SECONDS', 30),
    'max_retries'      => (int) env('LLM_MAX_RETRIES', 2),
];
