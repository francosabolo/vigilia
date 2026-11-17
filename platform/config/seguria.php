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

    /*
     * Following someone across cameras: a person seen by a nearby camera shortly after joins the same incident.
     */
    /*
     * Autonomy levels per zone. Deterrence fires only on clear threats and must be earned with closed alerts.
     */
    'autonomy' => [
        'deterrence_confidence' => (float) env('SEGURIA_DETERRENCE_CONFIDENCE', 0.85),
        'deterrence_message' => env('SEGURIA_DETERRENCE_MESSAGE', 'Atención: esta zona está vigilada. Lo estamos viendo y la guardia ya fue avisada.'),
        'eligibility_days' => 30,
        'eligibility_min_alerts' => 10,
        'eligibility_max_false_alarm_rate' => 0.10,
        // Two deterrences closed as false alarms within this many days demote the zone back to assisted.
        'demotion_window_days' => 7,
        'demotion_false_alarms' => 2,
    ],

    'tracking' => [
        'window_seconds' => (int) env('SEGURIA_TRACKING_WINDOW', 180),
        'max_distance_m' => (int) env('SEGURIA_TRACKING_DISTANCE', 150),
        'stopped_vehicle_minutes' => (int) env('SEGURIA_STOPPED_VEHICLE_MINUTES', 5),
    ],

    /*
     * Realistic images that dress simulated detections and cameras (the generated evaluation set).
     */
    'demo_images_path' => env('SEGURIA_DEMO_IMAGES_PATH'),

    /*
     * Days to keep detection images and clips. Evidence of open or confirmed incidents is always kept.
     */
    'retention_days' => (int) env('SEGURIA_RETENTION_DAYS', 30),

    /*
     * Allow the demo seeder in production (demo environments only).
     */
    'seed_demo' => (bool) env('SEGURIA_SEED_DEMO', false),

    /*
     * Require two-factor authentication for the central (admins and operators). On by default in production.
     */
    'require_two_factor' => (bool) env('SEGURIA_REQUIRE_2FA', env('APP_ENV') === 'production'),

    'ai' => [
        // Shadow mode: a challenger (another model and/or prompt) analyzes a sample of real detections in the
        // background, without deciding anything, to compare it with production before promoting it.
        'shadow' => [
            'enabled' => (bool) env('SEGURIA_SHADOW', false),
            'model' => env('SEGURIA_SHADOW_MODEL', 'claude-sonnet-5-5'),
            // Path (from the platform folder) to the challenger's system prompt; empty = production prompt.
            'prompt_file' => env('SEGURIA_SHADOW_PROMPT'),
            'sample_rate' => (float) env('SEGURIA_SHADOW_SAMPLE', 0.25),
            'daily_cap' => (int) env('SEGURIA_SHADOW_DAILY_CAP', 500),
            'include_simulated' => (bool) env('SEGURIA_SHADOW_SIMULATED', false),
        ],
        // Verifications per site per day before falling back to alerting without AI (0 = no cap).
        'daily_cap_per_site' => (int) env('SEGURIA_AI_DAILY_CAP', 3000),
        // On when there is a non-empty key (an empty ANTHROPIC_API_KEY= must not turn it on), unless forced.
        'enabled' => (bool) env('SEGURIA_AI_ENABLED', filled(env('ANTHROPIC_API_KEY'))),
        // Fast/cheap first pass, and a stronger model for ambiguous verdicts.
        'model' => env('SEGURIA_AI_MODEL', 'claude-haiku-5-5'),
        'review_model' => env('SEGURIA_AI_REVIEW_MODEL', 'claude-sonnet-5-5'),
        // Verdicts with confidence inside this range are re-checked with the review model.
        'review_band' => [0.35, 0.75],
        // Minimum confidence to raise an incident.
        'threat_threshold' => (float) env('SEGURIA_AI_THREAT_THRESHOLD', 0.5),
        // H5/H6 — estimated cost of one verification (image ~2.000 tokens in, ~150 out on Haiku, rounded up).
        'estimated_cost_per_call_usd' => (float) env('SEGURIA_AI_COST_PER_CALL', 0.0005),
        // H6 — seconds an operator would spend looking at each movement the AI filtered out.
        'manual_review_seconds' => (int) env('SEGURIA_MANUAL_REVIEW_SECONDS', 20),

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
