<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Tests\Unit;

use Lbose\ErrorAnalyzer\Analyzers\NullAnalyzer;
use Lbose\ErrorAnalyzer\Tests\TestCase;

class NullAnalyzerTest extends TestCase
{
    private NullAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new NullAnalyzer();
    }

    public function test_returns_placeholder_analysis(): void
    {
        $result = $this->analyzer->analyze(
            exceptionClass: 'RuntimeException',
            message: 'Test error',
            file: '/path/to/file.php',
            line: 42,
            sanitizedTrace: 'Stack trace...',
            sanitizedContext: ['url' => 'http://example.com'],
        );

        $this->assertIsArray($result);
        $this->assertSame('medium', $result['severity']);
        $this->assertSame('other', $result['category']);
        $this->assertStringContainsString('AI分析が無効化されています', $result['root_cause']);
    }

    public function test_returns_all_required_keys(): void
    {
        $result = $this->analyzer->analyze(
            exceptionClass: 'RuntimeException',
            message: 'Test error',
            file: '/path/to/file.php',
            line: 42,
            sanitizedTrace: 'Stack trace...',
            sanitizedContext: [],
        );

        $this->assertArrayHasKey('severity', $result);
        $this->assertArrayHasKey('category', $result);
        $this->assertArrayHasKey('root_cause', $result);
        $this->assertArrayHasKey('impact', $result);
        $this->assertArrayHasKey('immediate_action', $result);
        $this->assertArrayHasKey('recommended_fix', $result);
        $this->assertArrayHasKey('similar_issues', $result);
        $this->assertArrayHasKey('prevention', $result);
    }
}
