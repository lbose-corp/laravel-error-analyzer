<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Tests\Unit;

use Lbose\ErrorAnalyzer\IssueTrackers\NullIssueTracker;
use Lbose\ErrorAnalyzer\Models\ErrorReport;
use Lbose\ErrorAnalyzer\Tests\TestCase;

class NullIssueTrackerTest extends TestCase
{
    private NullIssueTracker $tracker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tracker = new NullIssueTracker();
    }

    public function test_returns_disabled_status(): void
    {
        $report = new ErrorReport([
            'exception_class' => 'RuntimeException',
            'message' => 'Test error',
            'file' => '/path/to/file.php',
            'line' => 42,
            'fingerprint' => 'abc123',
            'dedupe_window' => 12345,
            'trace' => 'Stack trace...',
            'severity' => 'high',
            'category' => 'other',
            'analysis' => [],
            'context' => [],
        ]);

        $result = $this->tracker->createIssue(
            report: $report,
            analysis: ['severity' => 'high', 'category' => 'other'],
            sanitizedTrace: 'Stack trace...',
            sanitizedContext: [],
        );

        $this->assertIsArray($result);
        $this->assertSame('disabled', $result['status']);
    }

    public function test_does_not_include_url_or_number(): void
    {
        $report = new ErrorReport([
            'exception_class' => 'RuntimeException',
            'message' => 'Test error',
            'file' => '/path/to/file.php',
            'line' => 42,
            'fingerprint' => 'abc123',
            'dedupe_window' => 12345,
            'trace' => 'Stack trace...',
            'severity' => 'high',
            'category' => 'other',
            'analysis' => [],
            'context' => [],
        ]);

        $result = $this->tracker->createIssue(
            report: $report,
            analysis: [],
            sanitizedTrace: '',
            sanitizedContext: [],
        );

        $this->assertArrayNotHasKey('url', $result);
        $this->assertArrayNotHasKey('number', $result);
    }
}
