<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Tests\Unit;

use Lbose\ErrorAnalyzer\Models\ErrorReport;
use Lbose\ErrorAnalyzer\Services\ErrorAnalysisService;
use Lbose\ErrorAnalyzer\Services\IssueTitleGenerators\GeminiIssueTitleGenerator;
use Lbose\ErrorAnalyzer\Tests\TestCase;
use RuntimeException;

class GeminiIssueTitleGeneratorTest extends TestCase
{
    public function test_returns_null_when_quota_is_exhausted(): void
    {
        config()->set('error-analyzer.analysis.daily_limit', 0);
        $quotaService = app(ErrorAnalysisService::class);
        $quotaService->resetDailyCount();

        $generator = new class($quotaService) extends GeminiIssueTitleGenerator
        {
            protected function assertGeminiDependencyIsInstalled(): void
            {
                // Dependency check is irrelevant for the quota-exhausted branch.
            }
        };

        $this->assertNull($generator->generateTitleSuffix(
            $this->makeReport(),
            ['root_cause' => 'root cause'],
            ['url' => 'https://example.test'],
        ));
    }

    public function test_does_not_consume_quota_when_gemini_dependency_is_missing(): void
    {
        if (class_exists(\Gemini\Laravel\Facades\Gemini::class)) {
            $this->markTestSkipped('Gemini dependency is installed in this environment.');
        }

        config()->set('error-analyzer.analysis.daily_limit', 100);
        $quotaService = app(ErrorAnalysisService::class);
        $quotaService->resetDailyCount();

        $generator = new GeminiIssueTitleGenerator($quotaService);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('google-gemini-php/laravel');

        try {
            $generator->generateTitleSuffix($this->makeReport(), [], []);
        } finally {
            $this->assertSame(0, $quotaService->getTodayCount());
        }
    }

    private function makeReport(): ErrorReport
    {
        $report = new ErrorReport([
            'exception_class' => 'RuntimeException',
            'message' => 'Database connection refused while loading users',
            'file' => '/path/to/file.php',
            'line' => 42,
            'fingerprint' => 'abc123',
            'dedupe_window' => 12345,
            'trace' => 'trace',
            'severity' => 'high',
            'category' => 'database',
            'analysis' => [],
            'context' => ['environment' => 'testing'],
            'occurred_at' => now(),
        ]);
        $report->id = 1;

        return $report;
    }
}
