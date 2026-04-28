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

    /*
    |--------------------------------------------------------------------------
    | AI reply assistant (PRD-005)
    |--------------------------------------------------------------------------
    |
    | Configuration for the OpenAI-powered draft reply generator. Laravel
    | computes availability deterministically; the AI only formulates the
    | text. The tonality prompts are placeholders until the pilot restaurant
    | finalises them (see issue #80).
    |
    */
    'ai' => [
        'openai_model' => env('OPENAI_MODEL', 'gpt-4o-mini'),

        'tonality_prompts' => [
            'formal' => 'Du schreibst im Namen eines gehobenen Restaurants. '
                .'Sprich den Gast in der Sie-Form an, halte die Sprache zurückhaltend, '
                .'sachlich und respektvoll. Vermeide Umgangssprache. (Platzhalter – '
                .'finaler Text wird mit Pilot-Restaurant abgestimmt.)',
            'casual' => 'Du schreibst im Namen eines entspannten Bistros. Sprich den '
                .'Gast in der Sie-Form an, halte den Ton freundlich und unkompliziert. '
                .'(Platzhalter – finaler Text wird mit Pilot-Restaurant abgestimmt.)',
            'family' => 'Du schreibst im Namen eines familienfreundlichen Restaurants. '
                .'Sprich den Gast in der Sie-Form an, halte die Sprache warm und '
                .'einladend. (Platzhalter – finaler Text wird mit Pilot-Restaurant '
                .'abgestimmt.)',
        ],

        /*
        | Constant rules appended to every system prompt regardless of
        | tonality. These guardrails are the single source of truth and are
        | consumed by OpenAiReplyGenerator. Do not duplicate them elsewhere.
        */
        'system_prompt_rules' => [
            'Antworte ausschließlich auf Deutsch.',
            'Verwende NUR die im User-JSON enthaltenen Zahlen und Zeiten. Erfinde keine eigenen.',
            'Wenn `is_open_at_desired_time` = false, biete höflich die `alternative_slots` an oder verweise auf `closed_reason`.',
            'Wenn `seats_free_at_desired` < `request.party_size`, lehne höflich ab und biete Alternativen an.',
            'Antworte in maximal 120 Wörtern.',
            'Keine Emojis, keine Hashtags, keine Marketing-Phrasen.',
            'Beginne mit Anrede („Guten Tag [Name],"), ende mit Grußformel und Restaurant-Name.',
        ],
    ],
];
