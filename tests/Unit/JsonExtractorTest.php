<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Tests\Unit;

use Lbose\ErrorAnalyzer\Helpers\JsonExtractor;
use Lbose\ErrorAnalyzer\Tests\TestCase;

class JsonExtractorTest extends TestCase
{
    public function test_extracts_json_from_code_block(): void
    {
        $text = '```json
{
  "severity": "high",
  "category": "database"
}
```';

        $result = JsonExtractor::extract($text);

        $this->assertStringNotContainsString('```', $result);
        $this->assertJson($result);

        $decoded = json_decode($result, true);
        $this->assertSame('high', $decoded['severity']);
        $this->assertSame('database', $decoded['category']);
    }

    public function test_extracts_json_from_embedded_text(): void
    {
        $text = 'Here is the analysis: {"severity": "low", "category": "other"} end';

        $result = JsonExtractor::extract($text);

        $this->assertJson($result);

        $decoded = json_decode($result, true);
        $this->assertSame('low', $decoded['severity']);
        $this->assertSame('other', $decoded['category']);
    }

    public function test_handles_nested_json_objects(): void
    {
        $text = '{"outer": {"inner": "value"}, "array": [1, 2, 3]}';

        $result = JsonExtractor::extract($text);

        $this->assertJson($result);

        $decoded = json_decode($result, true);
        $this->assertSame('value', $decoded['outer']['inner']);
        $this->assertSame([1, 2, 3], $decoded['array']);
    }

    public function test_handles_json_with_escaped_quotes(): void
    {
        $text = '{"message": "Error: \\"quoted\\" text"}';

        $result = JsonExtractor::extract($text);

        $this->assertJson($result);

        $decoded = json_decode($result, true);
        $this->assertStringContainsString('quoted', $decoded['message']);
    }
}
