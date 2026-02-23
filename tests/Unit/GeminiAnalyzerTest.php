<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Tests\Unit;

use Lbose\ErrorAnalyzer\Analyzers\GeminiAnalyzer;
use Lbose\ErrorAnalyzer\Tests\TestCase;
use RuntimeException;

class GeminiAnalyzerTest extends TestCase
{
    public function test_builds_correct_prompt(): void
    {
        $analyzer = new GeminiAnalyzer;
        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('buildAnalysisPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke(
            $analyzer,
            'RuntimeException',
            'Test error message',
            '/path/to/file.php',
            42,
            'Stack trace line 1\nStack trace line 2',
            ['url' => 'http://example.com', 'user_id' => 'user123'],
        );

        $this->assertStringContainsString('RuntimeException', $prompt);
        $this->assertStringContainsString('Test error message', $prompt);
        $this->assertStringContainsString('/path/to/file.php:42', $prompt);
        $this->assertStringContainsString('http://example.com', $prompt);
        $this->assertStringContainsString('user123', $prompt);
        $this->assertStringContainsString('JSON', $prompt);
        $this->assertStringContainsString('severity', $prompt);
        $this->assertStringContainsString('category', $prompt);
    }

    public function test_throws_runtime_exception_when_gemini_dependency_is_missing(): void
    {
        if (class_exists(\Gemini\Laravel\Facades\Gemini::class)) {
            $this->markTestSkipped('Gemini dependency is installed in this environment.');
        }

        $analyzer = new GeminiAnalyzer;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('google-gemini-php/laravel');

        $analyzer->analyze(
            'RuntimeException',
            'テスト用エラー',
            '/tmp/test.php',
            10,
            'trace',
            [],
        );
    }
}
