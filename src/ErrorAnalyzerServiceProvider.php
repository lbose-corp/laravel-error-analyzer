<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer;

use Illuminate\Support\ServiceProvider;
use Lbose\ErrorAnalyzer\Analyzers\GeminiAnalyzer;
use Lbose\ErrorAnalyzer\Analyzers\NullAnalyzer;
use Lbose\ErrorAnalyzer\Commands\CleanupOldErrors;
use Lbose\ErrorAnalyzer\Commands\TestErrorAnalysis;
use Lbose\ErrorAnalyzer\IssueTrackers\GithubIssueTracker;
use Lbose\ErrorAnalyzer\IssueTrackers\NullIssueTracker;
use Lbose\ErrorAnalyzer\Notifications\NullNotificationChannel;
use Lbose\ErrorAnalyzer\Notifications\SlackNotificationChannel;
use Lbose\ErrorAnalyzer\Services\Contracts\AiAnalyzerInterface;
use Lbose\ErrorAnalyzer\Services\Contracts\IssueTitleGeneratorInterface;
use Lbose\ErrorAnalyzer\Services\Contracts\IssueTrackerInterface;
use Lbose\ErrorAnalyzer\Services\Contracts\NotificationChannelInterface;
use Lbose\ErrorAnalyzer\Services\IssueTitleGenerators\GeminiIssueTitleGenerator;
use Lbose\ErrorAnalyzer\Services\IssueTitleGenerators\NullIssueTitleGenerator;

final class ErrorAnalyzerServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/error-analyzer.php',
            'error-analyzer',
        );

        // AI Analyzer
        $this->app->singleton(AiAnalyzerInterface::class, function ($app): AiAnalyzerInterface {
            $driver = config('error-analyzer.analyzer.driver', 'null');

            return match ($driver) {
                'gemini' => new GeminiAnalyzer,
                default => new NullAnalyzer,
            };
        });

        // Issue Title Generator (optional)
        $this->app->singleton(IssueTitleGeneratorInterface::class, function ($app): IssueTitleGeneratorInterface {
            $enabled = (bool) config('error-analyzer.issue_tracker.github.ai_title.enabled', false);

            if (! $enabled) {
                return new NullIssueTitleGenerator;
            }

            return $app->make(GeminiIssueTitleGenerator::class);
        });

        // Issue Tracker
        $this->app->singleton(IssueTrackerInterface::class, function ($app): IssueTrackerInterface {
            $driver = config('error-analyzer.issue_tracker.driver', 'null');

            return match ($driver) {
                'github' => $app->make(GithubIssueTracker::class),
                default => new NullIssueTracker,
            };
        });

        // Notification Channel
        $this->app->singleton(NotificationChannelInterface::class, function ($app): NotificationChannelInterface {
            $driver = config('error-analyzer.notification.driver', 'null');

            return match ($driver) {
                'slack' => new SlackNotificationChannel,
                default => new NullNotificationChannel,
            };
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // 設定のpublish
        $this->publishes([
            __DIR__.'/../config/error-analyzer.php' => config_path('error-analyzer.php'),
        ], ['error-analyzer', 'error-analyzer-config']);

        // マイグレーションのロード（開発環境用、DB保存が有効な場合のみ）
        $storageDriver = (string) config('error-analyzer.storage.driver', 'database');
        if ($storageDriver === 'database') {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        // コマンドの登録
        if ($this->app->runningInConsole()) {
            $this->commands([
                TestErrorAnalysis::class,
                CleanupOldErrors::class,
            ]);
        }
    }
}
