<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Tests\Feature;

use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Lbose\ErrorAnalyzer\Analyzers\NullAnalyzer;
use Lbose\ErrorAnalyzer\Helpers\FingerprintCalculator;
use Lbose\ErrorAnalyzer\Helpers\PiiSanitizer;
use Lbose\ErrorAnalyzer\IssueTrackers\NullIssueTracker;
use Lbose\ErrorAnalyzer\Jobs\AnalyzeErrorJob;
use Lbose\ErrorAnalyzer\Models\ErrorReport;
use Lbose\ErrorAnalyzer\Notifications\NullNotificationChannel;
use Lbose\ErrorAnalyzer\Services\Contracts\IssueTrackerInterface;
use Lbose\ErrorAnalyzer\Services\Contracts\NotificationChannelInterface;
use Lbose\ErrorAnalyzer\Tests\TestCase;

class AnalyzeErrorJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_error_report(): void
    {
        $exception = new Exception('Test error');

        $job = new AnalyzeErrorJob($exception, [
            'url' => 'http://example.com/test',
            'user_id' => 'user123',
            'environment' => 'testing',
        ]);

        $job->handle(
            analyzer: new NullAnalyzer,
            issueTracker: new NullIssueTracker,
            notificationChannel: new NullNotificationChannel,
            fingerprintCalculator: new FingerprintCalculator,
            piiSanitizer: new PiiSanitizer,
        );

        $this->assertDatabaseHas('error_reports', [
            'exception_class' => Exception::class,
            'message' => 'Test error',
        ]);
    }

    public function test_sanitizes_pii_in_context(): void
    {
        $exception = new Exception('Test error');

        $job = new AnalyzeErrorJob($exception, [
            'url' => 'http://example.com/test',
            'user_id' => 'user123',
            'email' => 'test@example.com', // Should be removed
            'password' => 'secret', // Should be removed
        ]);

        $job->handle(
            analyzer: new NullAnalyzer,
            issueTracker: new NullIssueTracker,
            notificationChannel: new NullNotificationChannel,
            fingerprintCalculator: new FingerprintCalculator,
            piiSanitizer: new PiiSanitizer,
        );

        $report = ErrorReport::latest()->first();

        $this->assertIsArray($report->context);
        $this->assertArrayHasKey('url', $report->context);
        $this->assertArrayHasKey('user_id', $report->context);
        $this->assertArrayNotHasKey('email', $report->context);
        $this->assertArrayNotHasKey('password', $report->context);
    }

    public function test_prevents_duplicate_errors_within_dedupe_window(): void
    {
        // Use the same exception instance to ensure same file/line/fingerprint
        $exception = new Exception('Duplicate error');

        // First error should be created
        $job1 = new AnalyzeErrorJob($exception, ['url' => 'http://example.com']);
        $job1->handle(
            analyzer: new NullAnalyzer,
            issueTracker: new NullIssueTracker,
            notificationChannel: new NullNotificationChannel,
            fingerprintCalculator: new FingerprintCalculator,
            piiSanitizer: new PiiSanitizer,
        );

        $this->assertDatabaseCount('error_reports', 1);

        // Second identical error within dedupe window should be skipped
        $job2 = new AnalyzeErrorJob($exception, ['url' => 'http://example.com']);
        $job2->handle(
            analyzer: new NullAnalyzer,
            issueTracker: new NullIssueTracker,
            notificationChannel: new NullNotificationChannel,
            fingerprintCalculator: new FingerprintCalculator,
            piiSanitizer: new PiiSanitizer,
        );

        // Should still be only 1 report
        $this->assertDatabaseCount('error_reports', 1);
    }

    public function test_stores_ai_analysis_results(): void
    {
        $exception = new Exception('Test error');

        $job = new AnalyzeErrorJob($exception, [
            'url' => 'http://example.com/test',
            'user_id' => 'user123',
        ]);

        $job->handle(
            analyzer: new NullAnalyzer,
            issueTracker: new NullIssueTracker,
            notificationChannel: new NullNotificationChannel,
            fingerprintCalculator: new FingerprintCalculator,
            piiSanitizer: new PiiSanitizer,
        );

        $report = ErrorReport::latest()->first();

        $this->assertIsArray($report->analysis);
        $this->assertArrayHasKey('severity', $report->analysis);
        $this->assertArrayHasKey('category', $report->analysis);
        $this->assertSame('medium', $report->severity);
        $this->assertSame('other', $report->category);
    }

    public function test_handles_different_exception_types(): void
    {
        $exception1 = new Exception('First error');
        $exception2 = new \RuntimeException('Second error');

        $job1 = new AnalyzeErrorJob($exception1);
        $job1->handle(
            analyzer: new NullAnalyzer,
            issueTracker: new NullIssueTracker,
            notificationChannel: new NullNotificationChannel,
            fingerprintCalculator: new FingerprintCalculator,
            piiSanitizer: new PiiSanitizer,
        );

        $job2 = new AnalyzeErrorJob($exception2);
        $job2->handle(
            analyzer: new NullAnalyzer,
            issueTracker: new NullIssueTracker,
            notificationChannel: new NullNotificationChannel,
            fingerprintCalculator: new FingerprintCalculator,
            piiSanitizer: new PiiSanitizer,
        );

        // Both should be created as they have different exception classes
        $this->assertDatabaseCount('error_reports', 2);

        $this->assertDatabaseHas('error_reports', [
            'exception_class' => Exception::class,
            'message' => 'First error',
        ]);

        $this->assertDatabaseHas('error_reports', [
            'exception_class' => \RuntimeException::class,
            'message' => 'Second error',
        ]);
    }

    public function test_works_without_database_storage(): void
    {
        // DB保存を無効化
        config()->set('error-analyzer.storage.driver', 'null');
        Cache::flush();

        $exception = new Exception('Test error without DB');

        $job = new AnalyzeErrorJob($exception, [
            'url' => 'http://example.com/test',
            'user_id' => 'user123',
            'environment' => 'testing',
        ]);

        $job->handle(
            analyzer: new NullAnalyzer,
            issueTracker: new NullIssueTracker,
            notificationChannel: new NullNotificationChannel,
            fingerprintCalculator: new FingerprintCalculator,
            piiSanitizer: new PiiSanitizer,
        );

        // DBには保存されないことを確認
        $this->assertDatabaseCount('error_reports', 0);
    }

    public function test_deduplication_works_with_cache_when_storage_is_null(): void
    {
        // DB保存を無効化
        config()->set('error-analyzer.storage.driver', 'null');
        Cache::flush();

        $exception = new Exception('Duplicate error without DB');

        // 最初のエラー
        $job1 = new AnalyzeErrorJob($exception, ['url' => 'http://example.com']);
        $job1->handle(
            analyzer: new NullAnalyzer,
            issueTracker: new NullIssueTracker,
            notificationChannel: new NullNotificationChannel,
            fingerprintCalculator: new FingerprintCalculator,
            piiSanitizer: new PiiSanitizer,
        );

        // 2回目の同一エラーはスキップされる
        $job2 = new AnalyzeErrorJob($exception, ['url' => 'http://example.com']);
        $job2->handle(
            analyzer: new NullAnalyzer,
            issueTracker: new NullIssueTracker,
            notificationChannel: new NullNotificationChannel,
            fingerprintCalculator: new FingerprintCalculator,
            piiSanitizer: new PiiSanitizer,
        );

        // DBには保存されないことを確認
        $this->assertDatabaseCount('error_reports', 0);
    }

    public function test_notification_and_issue_tracker_called_without_database(): void
    {
        // DB保存を無効化
        config()->set('error-analyzer.storage.driver', 'null');
        Cache::flush();

        $mockNotification = $this->createMock(NotificationChannelInterface::class);
        $mockNotification->expects($this->once())
            ->method('notify')
            ->with($this->isInstanceOf(ErrorReport::class));

        $mockIssueTracker = $this->createMock(IssueTrackerInterface::class);
        $mockIssueTracker->expects($this->once())
            ->method('createIssue')
            ->with(
                $this->isInstanceOf(ErrorReport::class),
                $this->isType('array'),
                $this->isType('string'),
                $this->isType('array'),
            )
            ->willReturn(['status' => 'disabled']);

        $exception = new Exception('Test error');

        $job = new AnalyzeErrorJob($exception, [
            'url' => 'http://example.com/test',
        ]);

        $job->handle(
            analyzer: new NullAnalyzer,
            issueTracker: $mockIssueTracker,
            notificationChannel: $mockNotification,
            fingerprintCalculator: new FingerprintCalculator,
            piiSanitizer: new PiiSanitizer,
        );

        // DBには保存されないことを確認
        $this->assertDatabaseCount('error_reports', 0);
    }
}
