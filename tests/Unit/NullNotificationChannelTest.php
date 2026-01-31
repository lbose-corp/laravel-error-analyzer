<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Tests\Unit;

use Lbose\ErrorAnalyzer\Models\ErrorReport;
use Lbose\ErrorAnalyzer\Notifications\NullNotificationChannel;
use Lbose\ErrorAnalyzer\Tests\TestCase;

class NullNotificationChannelTest extends TestCase
{
    private NullNotificationChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->channel = new NullNotificationChannel();
    }

    public function test_notify_does_nothing(): void
    {
        $report = new ErrorReport([
            'exception_class' => 'RuntimeException',
            'message' => 'Test error',
            'file' => '/path/to/file.php',
            'line' => 42,
            'fingerprint' => 'abc123',
            'dedupe_window' => 12345,
            'trace' => 'Stack trace...',
            'severity' => 'critical',
            'category' => 'other',
            'analysis' => [],
            'context' => [],
        ]);

        // Should not throw any exception
        $this->channel->notify($report);

        $this->assertTrue(true);
    }

    public function test_should_notify_always_returns_false(): void
    {
        $this->assertFalse($this->channel->shouldNotify('critical'));
        $this->assertFalse($this->channel->shouldNotify('high'));
        $this->assertFalse($this->channel->shouldNotify('medium'));
        $this->assertFalse($this->channel->shouldNotify('low'));
    }
}
