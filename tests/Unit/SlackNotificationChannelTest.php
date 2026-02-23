<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Tests\Unit;

use Illuminate\Support\Facades\Http;
use Lbose\ErrorAnalyzer\Models\ErrorReport;
use Lbose\ErrorAnalyzer\Notifications\SlackNotificationChannel;
use Lbose\ErrorAnalyzer\Tests\TestCase;

class SlackNotificationChannelTest extends TestCase
{
    private SlackNotificationChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = new SlackNotificationChannel;

        config()->set('error-analyzer.notification.slack.webhook', 'https://example.test/webhook');
        config()->set('error-analyzer.notification.slack.min_severity', 'high');
    }

    public function test_should_notify_respects_min_severity(): void
    {
        $this->assertTrue($this->channel->shouldNotify('critical'));
        $this->assertTrue($this->channel->shouldNotify('high'));
        $this->assertFalse($this->channel->shouldNotify('medium'));
        $this->assertFalse($this->channel->shouldNotify('low'));
    }

    public function test_should_notify_returns_false_for_unknown_severity(): void
    {
        $this->assertFalse($this->channel->shouldNotify('unknown'));
    }

    public function test_notify_uses_severity_specific_header_text(): void
    {
        Http::fake();

        $this->channel->notify($this->makeReport('high'));

        Http::assertSent(function ($request): bool {
            return $request['text'] === '⚠️ High Severity Error Detected';
        });
    }

    public function test_notify_uses_critical_header_for_critical_errors(): void
    {
        Http::fake();

        $this->channel->notify($this->makeReport('critical'));

        Http::assertSent(function ($request): bool {
            return $request['text'] === '🚨 Critical Error Detected';
        });
    }

    public function test_notify_does_not_send_when_webhook_is_missing(): void
    {
        Http::fake();
        config()->set('error-analyzer.notification.slack.webhook', '');

        $this->channel->notify($this->makeReport('critical'));

        Http::assertNothingSent();
    }

    private function makeReport(string $severity): ErrorReport
    {
        return new ErrorReport([
            'exception_class' => 'RuntimeException',
            'message' => 'Test error',
            'file' => '/path/to/file.php',
            'line' => 42,
            'fingerprint' => 'abc123',
            'dedupe_window' => 12345,
            'trace' => 'Stack trace...',
            'severity' => $severity,
            'category' => 'other',
            'analysis' => [
                'root_cause' => 'Something happened',
                'impact' => 'Request failed',
            ],
            'context' => [],
        ]);
    }
}
