<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Tests;

use Lbose\ErrorAnalyzer\ErrorAnalyzerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // マイグレーション実行
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ErrorAnalyzerServiceProvider::class,
        ];
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        // テスト用の設定
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // エラーアナライザーのテスト設定
        config()->set('error-analyzer.analyzer.driver', 'null');
        config()->set('error-analyzer.issue_tracker.driver', 'null');
        config()->set('error-analyzer.notification.driver', 'null');
        config()->set('error-analyzer.analysis.daily_limit', 100);
        config()->set('error-analyzer.analysis.enabled_environments', ['testing', 'production']);
        config()->set('error-analyzer.storage.driver', 'database'); // デフォルトはDB保存
    }
}
