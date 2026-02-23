<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Tests\Unit;

use Lbose\ErrorAnalyzer\Helpers\FingerprintCalculator;
use Lbose\ErrorAnalyzer\Tests\TestCase;

class FingerprintCalculatorTest extends TestCase
{
    private FingerprintCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new FingerprintCalculator;
    }

    /** @test */
    public function it_computes_fingerprint_from_exception_details(): void
    {
        $fingerprint = $this->calculator->compute(
            'RuntimeException',
            '/app/test.php',
            42,
        );

        $this->assertIsString($fingerprint);
        $this->assertEquals(64, strlen($fingerprint)); // SHA256 = 64 hex characters
    }

    /** @test */
    public function it_computes_same_fingerprint_for_same_exception(): void
    {
        $fingerprint1 = $this->calculator->compute('RuntimeException', '/app/test.php', 42);
        $fingerprint2 = $this->calculator->compute('RuntimeException', '/app/test.php', 42);

        $this->assertEquals($fingerprint1, $fingerprint2);
    }

    /** @test */
    public function it_computes_different_fingerprint_for_different_exception(): void
    {
        $fingerprint1 = $this->calculator->compute('RuntimeException', '/app/test.php', 42);
        $fingerprint2 = $this->calculator->compute('RuntimeException', '/app/test.php', 43);

        $this->assertNotEquals($fingerprint1, $fingerprint2);
    }

    /** @test */
    public function it_computes_dedupe_window_for_timestamp(): void
    {
        $timestamp = 1609459200; // 2021-01-01 00:00:00 UTC
        $window = $this->calculator->computeDedupeWindow($timestamp, 5);

        $this->assertEquals(5364864, $window);
    }

    /** @test */
    public function it_computes_same_dedupe_window_for_timestamps_within_window(): void
    {
        $timestamp1 = 1609459200; // 2021-01-01 00:00:00 UTC
        $timestamp2 = 1609459290; // 2021-01-01 00:01:30 UTC (90 seconds later)

        $window1 = $this->calculator->computeDedupeWindow($timestamp1, 5);
        $window2 = $this->calculator->computeDedupeWindow($timestamp2, 5);

        $this->assertEquals($window1, $window2);
    }

    /** @test */
    public function it_computes_different_dedupe_window_for_timestamps_outside_window(): void
    {
        $timestamp1 = 1609459200; // 2021-01-01 00:00:00 UTC
        $timestamp2 = 1609459500; // 2021-01-01 00:05:00 UTC (5 minutes later)

        $window1 = $this->calculator->computeDedupeWindow($timestamp1, 5);
        $window2 = $this->calculator->computeDedupeWindow($timestamp2, 5);

        $this->assertNotEquals($window1, $window2);
    }
}
