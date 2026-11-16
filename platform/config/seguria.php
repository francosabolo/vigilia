<?php

return [
    /*
     * Disk where snapshots and clips are stored (use an S3-compatible bucket on Laravel Cloud).
     */
    'media_disk' => env('SEGURIA_MEDIA_DISK', 'local'),

    /*
     * Seconds after which an edge device without heartbeat is considered offline.
     */
    'edge_offline_after' => (int) env('SEGURIA_EDGE_OFFLINE_AFTER', 180),

    /*
     * Detections of the same camera/zone within this window are grouped into one incident.
     */
    'dedup_seconds' => (int) env('SEGURIA_DEDUP_SECONDS', 120),

    'ai' => [
        'enabled' => (bool) env('SEGURIA_AI_ENABLED', env('ANTHROPIC_API_KEY') !== null),
        // Fast/cheap first pass, and a stronger model for ambiguous verdicts.
        'model' => env('SEGURIA_AI_MODEL', 'claude-haiku-5-5'),
        'review_model' => env('SEGURIA_AI_REVIEW_MODEL', 'claude-sonnet-5-5'),
        // Verdicts with confidence inside this range are re-checked with the review model.
        'review_band' => [0.35, 0.75],
        // Minimum confidence to raise an incident.
        'threat_threshold' => (float) env('SEGURIA_AI_THREAT_THRESHOLD', 0.5),
        // List prices in USD per million tokens [input, output], used to report real evaluation cost.
        'prices' => [
            'claude-haiku-5-5' => [0.10, 0.50],
            'claude-sonnet-5-5' => [2.00, 10.00],
            'claude-opus-5-5' => [4.00, 20.00],
        ],
    ],

    'whatsapp' => [
        'enabled' => (bool) env('WHATSAPP_ENABLED', false),
        'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v23.0'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        // Approved template: header image, body {{1}} title {{2}} site {{3}} description, 2 quick-reply buttons.
        'template' => env('WHATSAPP_TEMPLATE', 'alerta_intrusion'),
        'template_language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'es_AR'),
    ],
];
