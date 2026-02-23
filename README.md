# Laravel Error Analyzer

AI-driven error analysis, GitHub issue creation, and Slack notifications for Laravel applications.

## Features

- 🤖 **AI-Powered Analysis**: Analyze errors using Google Gemini AI
- 🐛 **Automatic Issue Creation**: Create GitHub issues for critical errors
- 📢 **Slack Notifications**: Send notifications to Slack channels
- 🔒 **PII Protection**: Automatically sanitize sensitive data from stack traces
- 🚦 **Quota Management**: Daily limits to prevent API abuse
- 🔄 **Deduplication**: Avoid analyzing the same error multiple times
- 🎯 **Flexible Configuration**: Easily enable/disable features via environment variables

## Requirements

- PHP 8.3 or higher
- Laravel 11.0 or higher

## Installation

Install the package via Composer:

```bash
composer require lbose/laravel-error-analyzer
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=error-analyzer-config
```

Run migrations (package migrations are auto-loaded when `ERROR_ANALYZER_STORAGE_DRIVER=database`):

```bash
php artisan migrate
```

## Configuration

### Environment Variables

Add these variables to your `.env` file:

```env
# AI Analyzer (optional)
ERROR_ANALYZER_DRIVER=gemini
ERROR_ANALYZER_GEMINI_API_KEY=your-gemini-api-key

# Issue Tracker (optional)
ERROR_ANALYZER_ISSUE_TRACKER=github
ERROR_ANALYZER_GITHUB_TOKEN=your-github-token
ERROR_ANALYZER_GITHUB_REPOSITORY=username/repository
ERROR_ANALYZER_GITHUB_AI_TITLE_ENABLED=false
ERROR_ANALYZER_GITHUB_AI_TITLE_MODEL=gemini-2.5-flash-lite

# Notifications (optional)
ERROR_ANALYZER_NOTIFICATION=slack
ERROR_ANALYZER_SLACK_WEBHOOK=https://hooks.slack.com/services/xxx

# Analysis Settings
ERROR_ANALYZER_DAILY_LIMIT=100
ERROR_ANALYZER_ENABLED_ENVIRONMENTS=production,staging

# Storage Settings (optional)
ERROR_ANALYZER_STORAGE_DRIVER=database  # 'database' (default) or 'null' (disable DB storage)
```

### Optional Dependencies

Install optional dependencies based on your needs:

```bash
# For Gemini AI analysis
composer require google-gemini-php/laravel
```

If `ERROR_ANALYZER_GITHUB_AI_TITLE_ENABLED=true`, issue titles are optionally generated with Gemini and automatically fall back to the existing rule-based title when generation fails. Title generation uses the same `ERROR_ANALYZER_DAILY_LIMIT` quota as AI error analysis.

## Basic Usage

### Exception Handler Integration

Add error analysis to your exception handler:

```php
use Lbose\ErrorAnalyzer\Jobs\AnalyzeErrorJob;
use Lbose\ErrorAnalyzer\Services\ErrorAnalysisService;

class Handler extends ExceptionHandler
{
    public function report(Throwable $exception): void
    {
        parent::report($exception);

        if ($this->shouldAnalyze($exception)) {
            $service = app(ErrorAnalysisService::class);

            if ($service->tryIncrementIfAllowed()) {
                dispatch(new AnalyzeErrorJob($exception, [
                    'environment' => app()->environment(),
                    'timestamp' => now()->toIso8601String(),
                    'url' => request()->fullUrl(),
                    'user_id' => auth()->check() ? (string) auth()->id() : 'guest',
                ]));
            }
        }
    }

    private function shouldAnalyze(Throwable $exception): bool
    {
        $enabledEnvironments = config('error-analyzer.analysis.enabled_environments', []);
        if (!in_array(app()->environment(), $enabledEnvironments, true)) {
            return false;
        }

        $excluded = config('error-analyzer.analysis.excluded_exceptions', []);
        foreach ($excluded as $excludedException) {
            if ($exception instanceof $excludedException) {
                return false;
            }
        }

        return true;
    }
}
```

### Artisan Commands

Test error analysis:

```bash
# Trigger a test error
php artisan errors:test-analysis --type=runtime

# List all error reports
php artisan errors:test-analysis --list

# Show specific error report
php artisan errors:test-analysis --show=1
```

Cleanup old errors:

```bash
php artisan errors:cleanup
```

## Advanced Configuration

### Custom Analyzers

You can implement your own AI analyzer:

```php
use Lbose\ErrorAnalyzer\Services\Contracts\AiAnalyzerInterface;

class CustomAnalyzer implements AiAnalyzerInterface
{
    public function analyze(
        string $exceptionClass,
        string $message,
        string $file,
        int $line,
        string $sanitizedTrace,
        array $sanitizedContext
    ): array {
        // Your custom implementation
        return [
            'severity' => 'high',
            'category' => 'other',
            'root_cause' => 'Custom analysis',
            'impact' => 'Custom impact',
            'immediate_action' => 'Custom immediate action',
            'recommended_fix' => 'Custom recommended fix',
            'similar_issues' => [],
            'prevention' => 'Custom prevention',
        ];
    }
}
```

Register your custom analyzer in a service provider:

```php
$this->app->singleton(AiAnalyzerInterface::class, CustomAnalyzer::class);
```

### Storage Configuration

By default, error reports are stored in the database. You can disable database storage by setting:

```env
ERROR_ANALYZER_STORAGE_DRIVER=null
```

When database storage is disabled:
- Error reports are not saved to the database
- Deduplication uses cache instead of database unique constraints
- AI analysis, notifications, and issue creation still work normally
- Commands like `errors:test-analysis --list` and `errors:cleanup` will not work (they require database storage)

### Configuration Options

See `config/error-analyzer.php` for all available options:

- AI analyzer settings (driver, model, temperature, etc.)
- Issue tracker settings (repository, labels, assignees)
- Notification settings (webhook, severity threshold)
- Analysis behavior (daily limit, excluded exceptions, deduplication)
- Storage settings (driver, table name, cleanup days)

## Architecture

The package uses a driver-based architecture with the following components:

- **AiAnalyzerInterface**: AI-based error analysis (Gemini, custom, or null)
- **IssueTrackerInterface**: Automatic issue creation (GitHub or null)
- **NotificationChannelInterface**: Error notifications (Slack or null)
- **ErrorAnalysisService**: Quota management and rate limiting
- **FingerprintCalculator**: Error deduplication logic
- **PiiSanitizer**: PII removal from stack traces and context

PII masking currently targets common patterns (email, UUID, long hex tokens, Bearer/JWT-like tokens, some API keys). Application-specific secrets may still require custom masking.

## Testing

The package includes comprehensive tests:

```bash
cd packages/laravel-error-analyzer
composer install
vendor/bin/pint --test
vendor/bin/phpstan analyse -l 6 --memory-limit=1G
vendor/bin/phpunit
```

## License

MIT License. See [LICENSE](LICENSE) for details.

## Credits

Developed by [LBOSE Corp](https://lbose.co.jp)
