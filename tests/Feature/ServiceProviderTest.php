<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Tests\Feature;

use Lbose\ErrorAnalyzer\Analyzers\GeminiAnalyzer;
use Lbose\ErrorAnalyzer\Analyzers\NullAnalyzer;
use Lbose\ErrorAnalyzer\IssueTrackers\GithubIssueTracker;
use Lbose\ErrorAnalyzer\IssueTrackers\NullIssueTracker;
use Lbose\ErrorAnalyzer\Notifications\NullNotificationChannel;
use Lbose\ErrorAnalyzer\Notifications\SlackNotificationChannel;
use Lbose\ErrorAnalyzer\Services\Contracts\AiAnalyzerInterface;
use Lbose\ErrorAnalyzer\Services\Contracts\IssueTrackerInterface;
use Lbose\ErrorAnalyzer\Services\Contracts\NotificationChannelInterface;
use Lbose\ErrorAnalyzer\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_registers_null_analyzer_by_default(): void
    {
        config(['error-analyzer.analyzer.driver' => 'null']);

        $analyzer = app(AiAnalyzerInterface::class);

        $this->assertInstanceOf(NullAnalyzer::class, $analyzer);
    }

    public function test_registers_gemini_analyzer_when_configured(): void
    {
        config(['error-analyzer.analyzer.driver' => 'gemini']);

        $analyzer = app(AiAnalyzerInterface::class);

        $this->assertInstanceOf(GeminiAnalyzer::class, $analyzer);
    }

    public function test_registers_null_issue_tracker_by_default(): void
    {
        config(['error-analyzer.issue_tracker.driver' => 'null']);

        $tracker = app(IssueTrackerInterface::class);

        $this->assertInstanceOf(NullIssueTracker::class, $tracker);
    }

    public function test_registers_github_issue_tracker_when_configured(): void
    {
        config(['error-analyzer.issue_tracker.driver' => 'github']);

        $tracker = app(IssueTrackerInterface::class);

        $this->assertInstanceOf(GithubIssueTracker::class, $tracker);
    }

    public function test_registers_null_notification_channel_by_default(): void
    {
        config(['error-analyzer.notification.driver' => 'null']);

        $channel = app(NotificationChannelInterface::class);

        $this->assertInstanceOf(NullNotificationChannel::class, $channel);
    }

    public function test_registers_slack_notification_channel_when_configured(): void
    {
        config(['error-analyzer.notification.driver' => 'slack']);

        $channel = app(NotificationChannelInterface::class);

        $this->assertInstanceOf(SlackNotificationChannel::class, $channel);
    }

    public function test_registers_commands(): void
    {
        $commands = $this->app['Illuminate\Contracts\Console\Kernel']->all();

        $this->assertArrayHasKey('errors:test-analysis', $commands);
        $this->assertArrayHasKey('errors:cleanup', $commands);
    }

    public function test_merges_configuration(): void
    {
        $this->assertNotNull(config('error-analyzer.analyzer.driver'));
        $this->assertNotNull(config('error-analyzer.issue_tracker.driver'));
        $this->assertNotNull(config('error-analyzer.notification.driver'));
        $this->assertNotNull(config('error-analyzer.analysis.daily_limit'));
        $this->assertNotNull(config('error-analyzer.storage.table_name'));
    }

    public function test_singletons_are_shared(): void
    {
        $analyzer1 = app(AiAnalyzerInterface::class);
        $analyzer2 = app(AiAnalyzerInterface::class);

        $this->assertSame($analyzer1, $analyzer2);

        $tracker1 = app(IssueTrackerInterface::class);
        $tracker2 = app(IssueTrackerInterface::class);

        $this->assertSame($tracker1, $tracker2);

        $channel1 = app(NotificationChannelInterface::class);
        $channel2 = app(NotificationChannelInterface::class);

        $this->assertSame($channel1, $channel2);
    }
}
