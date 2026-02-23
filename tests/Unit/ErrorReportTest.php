<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Tests\Unit;

use Lbose\ErrorAnalyzer\Models\ErrorReport;
use Lbose\ErrorAnalyzer\Tests\TestCase;

class ErrorReportTest extends TestCase
{
    public function test_uses_configured_table_name(): void
    {
        config(['error-analyzer.storage.table_name' => 'custom_errors']);

        $report = new ErrorReport;

        $this->assertSame('custom_errors', $report->getTable());
    }

    public function test_uses_default_table_name(): void
    {
        config(['error-analyzer.storage.table_name' => null]);

        $report = new ErrorReport;

        $this->assertSame('error_reports', $report->getTable());
    }

    public function test_casts_attributes_correctly(): void
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
            'category' => 'database',
            'analysis' => ['key' => 'value'],
            'context' => ['url' => 'http://example.com'],
            'occurred_at' => now(),
        ]);

        $this->assertIsArray($report->analysis);
        $this->assertIsArray($report->context);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $report->occurred_at);
    }

    public function test_root_cause_accessor(): void
    {
        $report = new ErrorReport([
            'analysis' => ['root_cause' => 'Database connection failed'],
        ]);

        $this->assertSame('Database connection failed', $report->root_cause);
    }

    public function test_root_cause_accessor_returns_null_when_missing(): void
    {
        $report = new ErrorReport([
            'analysis' => [],
        ]);

        $this->assertNull($report->root_cause);
    }

    public function test_recommended_fix_accessor(): void
    {
        $report = new ErrorReport([
            'analysis' => ['recommended_fix' => 'Check database credentials'],
        ]);

        $this->assertSame('Check database credentials', $report->recommended_fix);
    }

    public function test_recommended_fix_accessor_returns_null_when_missing(): void
    {
        $report = new ErrorReport([
            'analysis' => [],
        ]);

        $this->assertNull($report->recommended_fix);
    }
}
