<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Quarantine retention for failed_email_imports
    |--------------------------------------------------------------------------
    |
    | DSGVO-driven retention policy for the email-ingest quarantine table.
    | See docs/decisions/failed-email-imports-retention.md for the reasoning,
    | audit-log shape, and triggers for changing the window.
    |
    */
    'failed_email_imports' => [
        'prune' => [
            'enabled' => (bool) env('FAILED_EMAIL_IMPORTS_PRUNE_ENABLED', true),
            'retention_days' => (int) env('FAILED_EMAIL_IMPORTS_RETENTION_DAYS', 30),
        ],
    ],
];
