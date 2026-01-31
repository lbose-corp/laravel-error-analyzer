<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Tests\Unit;

use Lbose\ErrorAnalyzer\Analyzers\GeminiAnalyzer;
use Lbose\ErrorAnalyzer\Tests\TestCase;

class GeminiAnalyzerTest extends TestCase
{
    public function test_extracts_json_from_code_block(): void
    {
        $analyzer = new GeminiAnalyzer();
        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('extractJsonFromText');
        $method->setAccessible(true);

        $text = '```json
{
  "severity": "high",
  "category": "database"
}
```';

        $result = $method->invoke($analyzer, $text);

        $this->assertStringNotContainsString('```', $result);
        $this->assertJson($result);

        $decoded = json_decode($result, true);
        $this->assertSame('high', $decoded['severity']);
        $this->assertSame('database', $decoded['category']);
    }

    public function test_extracts_json_from_plain_text(): void
    {
        $analyzer = new GeminiAnalyzer();
        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('extractJsonFromText');
        $method->setAccessible(true);

        $text = 'Here is the analysis: {"severity": "low", "category": "other"} end';

        $result = $method->invoke($analyzer, $text);

        $this->assertJson($result);

        $decoded = json_decode($result, true);
        $this->assertSame('low', $decoded['severity']);
        $this->assertSame('other', $decoded['category']);
    }

    public function test_handles_nested_json_objects(): void
    {
        $analyzer = new GeminiAnalyzer();
        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('extractJsonFromText');
        $method->setAccessible(true);

        $text = '{"outer": {"inner": "value"}, "array": [1, 2, 3]}';

        $result = $method->invoke($analyzer, $text);

        $this->assertJson($result);

        $decoded = json_decode($result, true);
        $this->assertSame('value', $decoded['outer']['inner']);
        $this->assertSame([1, 2, 3], $decoded['array']);
    }

    public function test_handles_json_with_escaped_quotes(): void
    {
        $analyzer = new GeminiAnalyzer();
        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('extractJsonFromText');
        $method->setAccessible(true);

        $text = '{"message": "Error: \\"quoted\\" text"}';

        $result = $method->invoke($analyzer, $text);

        $this->assertJson($result);

        $decoded = json_decode($result, true);
        $this->assertStringContainsString('quoted', $decoded['message']);
    }

    public function test_builds_correct_prompt(): void
    {
        $analyzer = new GeminiAnalyzer();
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

    public function test_throws_exception_when_response_is_empty(): void
    {
        // This test would require mocking the Gemini facade
        // Since it's an optional dependency, we'll skip the full integration test
        $this->markTestSkipped('Requires Gemini facade mocking');
    }

    public function test_throws_exception_when_json_parsing_fails(): void
    {
        // This test would require mocking the Gemini facade
        // Since it's an optional dependency, we'll skip the full integration test
        $this->markTestSkipped('Requires Gemini facade mocking');
    }
}
