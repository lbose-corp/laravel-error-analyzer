<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Lbose\ErrorAnalyzer\Jobs\AnalyzeErrorJob;
use Lbose\ErrorAnalyzer\Models\ErrorReport;
use Lbose\ErrorAnalyzer\Tests\TestCase;

class TestErrorAnalysisCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_triggers_runtime_exception(): void
    {
        Queue::fake();

        $this->artisan('errors:test-analysis', ['--type' => 'runtime'])
            ->expectsOutputToContain('エラー分析のテストを開始します')
            ->assertExitCode(0);

        Queue::assertPushed(AnalyzeErrorJob::class);
    }

    public function test_triggers_database_exception(): void
    {
        Queue::fake();

        $this->artisan('errors:test-analysis', ['--type' => 'query'])
            ->expectsOutputToContain('エラー分析のテストを開始します')
            ->assertExitCode(0);

        Queue::assertPushed(AnalyzeErrorJob::class);
    }

    public function test_triggers_unexpected_exception(): void
    {
        Queue::fake();

        $this->artisan('errors:test-analysis', ['--type' => 'unexpected'])
            ->expectsOutputToContain('エラー分析のテストを開始します')
            ->assertExitCode(0);

        Queue::assertPushed(AnalyzeErrorJob::class);
    }

    public function test_lists_error_reports(): void
    {
        // Create some test error reports
        ErrorReport::create([
            'exception_class' => 'RuntimeException',
            'message' => 'Test error 1',
            'file' => '/path/to/file.php',
            'line' => 42,
            'fingerprint' => 'fingerprint-1',
            'dedupe_window' => 12345,
            'trace' => 'Stack trace...',
            'severity' => 'high',
            'category' => 'database',
            'analysis' => [],
            'context' => [],
            'occurred_at' => now(),
        ]);

        ErrorReport::create([
            'exception_class' => 'RuntimeException',
            'message' => 'Test error 2',
            'file' => '/path/to/file.php',
            'line' => 43,
            'fingerprint' => 'fingerprint-2',
            'dedupe_window' => 12346,
            'trace' => 'Stack trace...',
            'severity' => 'medium',
            'category' => 'api',
            'analysis' => [],
            'context' => [],
            'occurred_at' => now(),
        ]);

        $this->artisan('errors:test-analysis', ['--list' => true])
            ->expectsOutputToContain('最新の')
            ->expectsOutputToContain('詳細を確認するには')
            ->assertExitCode(0);
    }

    public function test_shows_error_report_detail(): void
    {
        $report = ErrorReport::create([
            'exception_class' => 'RuntimeException',
            'message' => 'Test error detail',
            'file' => '/path/to/file.php',
            'line' => 42,
            'fingerprint' => 'fingerprint-detail',
            'dedupe_window' => 12345,
            'trace' => 'Stack trace...',
            'severity' => 'critical',
            'category' => 'database',
            'analysis' => [
                'root_cause' => 'Database connection failed',
                'recommended_fix' => 'Check database credentials',
            ],
            'context' => ['url' => 'http://example.com'],
            'occurred_at' => now(),
        ]);

        $this->artisan('errors:test-analysis', ['--show' => $report->id])
            ->expectsOutputToContain('エラーレポート詳細')
            ->expectsOutputToContain('RuntimeException')
            ->expectsOutputToContain('critical')
            ->assertExitCode(0);
    }

    public function test_sync_option_runs_immediately(): void
    {
        Queue::fake();

        $this->artisan('errors:test-analysis', ['--type' => 'runtime', '--sync' => true])
            ->expectsOutputToContain('ジョブを同期的に実行します')
            ->assertExitCode(0);

        // In sync mode, job should not be queued
        Queue::assertNothingPushed();

        // Instead, error report should be created directly
        $this->assertDatabaseCount('error_reports', 1);
    }

    public function test_handles_invalid_error_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('不明なエラータイプ');

        $this->artisan('errors:test-analysis', ['--type' => 'invalid']);
    }

    public function test_handles_non_existent_error_report(): void
    {
        $this->artisan('errors:test-analysis', ['--show' => 999999])
            ->expectsOutputToContain('エラーレポートが見つかりませんでした')
            ->assertExitCode(1);
    }

    public function test_list_fails_when_storage_is_null(): void
    {
        // DB保存を無効化
        config()->set('error-analyzer.storage.driver', 'null');

        $this->artisan('errors:test-analysis', ['--list' => true])
            ->expectsOutputToContain('DB保存が無効になっています')
            ->assertExitCode(1);
    }

    public function test_show_fails_when_storage_is_null(): void
    {
        // DB保存を無効化
        config()->set('error-analyzer.storage.driver', 'null');

        $this->artisan('errors:test-analysis', ['--show' => 1])
            ->expectsOutputToContain('DB保存が無効になっています')
            ->assertExitCode(1);
    }
}
