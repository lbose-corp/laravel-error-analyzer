<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | AI Analyzer Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the AI-based error analysis provider. Supported drivers:
    | - 'gemini': Use Google Gemini AI for error analysis
    | - 'null': Disable AI analysis (default)
    |
    */
    'analyzer' => [
        'driver' => env('ERROR_ANALYZER_DRIVER', 'null'),

        'gemini' => [
            'api_key' => env('ERROR_ANALYZER_GEMINI_API_KEY', env('GEMINI_API_KEY')),
            'model' => env('ERROR_ANALYZER_GEMINI_MODEL', 'gemini-2.5-flash'),
            'temperature' => env('ERROR_ANALYZER_GEMINI_TEMPERATURE', 0.3),
            'max_output_tokens' => env('ERROR_ANALYZER_GEMINI_MAX_TOKENS', 8000),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Issue Tracker Configuration
    |--------------------------------------------------------------------------
    |
    | Configure automatic issue creation. Supported drivers:
    | - 'github': Create GitHub issues for errors
    | - 'null': Disable issue creation (default)
    |
    */
    'issue_tracker' => [
        'driver' => env('ERROR_ANALYZER_ISSUE_TRACKER', 'null'),

        'github' => [
            'token' => env('ERROR_ANALYZER_GITHUB_TOKEN'),
            'repository' => env('ERROR_ANALYZER_GITHUB_REPOSITORY'),
            'labels' => explode(',', env('ERROR_ANALYZER_GITHUB_LABELS', 'bug,error-analysis')),
            'assignees' => explode(',', env('ERROR_ANALYZER_GITHUB_ASSIGNEES', '')),
            'ai_title' => [
                'enabled' => (bool) env('ERROR_ANALYZER_GITHUB_AI_TITLE_ENABLED', false),
                'model' => env('ERROR_ANALYZER_GITHUB_AI_TITLE_MODEL', 'gemini-2.5-flash-lite'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Configuration
    |--------------------------------------------------------------------------
    |
    | Configure error notifications. Supported drivers:
    | - 'slack': Send Slack notifications for critical errors
    | - 'null': Disable notifications (default)
    |
    */
    'notification' => [
        'driver' => env('ERROR_ANALYZER_NOTIFICATION', 'null'),

        'slack' => [
            'webhook' => env('ERROR_ANALYZER_SLACK_WEBHOOK'),
            'min_severity' => env('ERROR_ANALYZER_SLACK_MIN_SEVERITY', 'high'),
            'channel' => env('ERROR_ANALYZER_SLACK_CHANNEL'),
            'username' => env('ERROR_ANALYZER_SLACK_USERNAME', 'Error Analyzer'),
            'icon' => env('ERROR_ANALYZER_SLACK_ICON', ':warning:'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Analysis Configuration
    |--------------------------------------------------------------------------
    |
    | Configure error analysis behavior.
    |
    */
    'analysis' => [
        // Daily limit for AI analysis calls
        'daily_limit' => (int) env('ERROR_ANALYZER_DAILY_LIMIT', 100),

        // Enabled environments (e.g., 'production', 'staging')
        'enabled_environments' => explode(',', env('ERROR_ANALYZER_ENABLED_ENVIRONMENTS', 'production')),

        // Exception classes to exclude from analysis
        'excluded_exceptions' => [
            \Illuminate\Validation\ValidationException::class,
            \Illuminate\Auth\AuthenticationException::class,
            \Illuminate\Auth\Access\AuthorizationException::class,
            \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        ],

        // Deduplication window in minutes (default: 5 minutes)
        'dedupe_window_minutes' => (int) env('ERROR_ANALYZER_DEDUPE_WINDOW', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Configure error report storage. Supported drivers:
    | - 'database': Store error reports in database (default)
    | - 'null': Disable database storage (use cache for deduplication)
    |
    */
    'storage' => [
        // Storage driver: 'database' or 'null'
        'driver' => env('ERROR_ANALYZER_STORAGE_DRIVER', 'database'),

        // Database table name for error reports
        'table_name' => env('ERROR_ANALYZER_TABLE_NAME', 'error_reports'),

        // Days to keep old error reports (cleanup command)
        'cleanup_days' => (int) env('ERROR_ANALYZER_CLEANUP_DAYS', 90),
    ],
];
