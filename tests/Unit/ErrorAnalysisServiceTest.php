<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Lbose\ErrorAnalyzer\Services\ErrorAnalysisService;
use Lbose\ErrorAnalyzer\Tests\TestCase;

class ErrorAnalysisServiceTest extends TestCase
{
    private ErrorAnalysisService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ErrorAnalysisService;
        Cache::flush();
    }

    /** @test */
    public function it_returns_true_when_quota_is_available(): void
    {
        config()->set('error-analyzer.analysis.daily_limit', 10);

        $this->assertTrue($this->service->canAnalyze());
    }

    /** @test */
    public function it_returns_false_when_quota_is_exhausted(): void
    {
        config()->set('error-analyzer.analysis.daily_limit', 5);

        // クォータを使い果たす
        for ($i = 0; $i < 5; $i++) {
            $this->service->tryIncrementIfAllowed();
        }

        $this->assertFalse($this->service->canAnalyze());
    }

    /** @test */
    public function it_increments_count_atomically(): void
    {
        config()->set('error-analyzer.analysis.daily_limit', 100);

        $result1 = $this->service->tryIncrementIfAllowed();
        $result2 = $this->service->tryIncrementIfAllowed();

        $this->assertTrue($result1);
        $this->assertTrue($result2);
        $this->assertEquals(2, $this->service->getTodayCount());
    }

    /** @test */
    public function it_returns_false_when_limit_is_reached(): void
    {
        config()->set('error-analyzer.analysis.daily_limit', 2);

        $result1 = $this->service->tryIncrementIfAllowed();
        $result2 = $this->service->tryIncrementIfAllowed();
        $result3 = $this->service->tryIncrementIfAllowed();

        $this->assertTrue($result1);
        $this->assertTrue($result2);
        $this->assertFalse($result3);
    }

    /** @test */
    public function it_calculates_remaining_quota_correctly(): void
    {
        config()->set('error-analyzer.analysis.daily_limit', 10);

        $this->assertEquals(10, $this->service->getRemainingQuota());

        $this->service->tryIncrementIfAllowed();
        $this->assertEquals(9, $this->service->getRemainingQuota());

        $this->service->tryIncrementIfAllowed();
        $this->assertEquals(8, $this->service->getRemainingQuota());
    }

    /** @test */
    public function it_resets_daily_count(): void
    {
        config()->set('error-analyzer.analysis.daily_limit', 100);

        $this->service->tryIncrementIfAllowed();
        $this->service->tryIncrementIfAllowed();
        $this->assertEquals(2, $this->service->getTodayCount());

        $this->service->resetDailyCount();
        $this->assertEquals(0, $this->service->getTodayCount());
    }
}
