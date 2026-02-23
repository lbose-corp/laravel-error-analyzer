<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Tests\Unit;

use Lbose\ErrorAnalyzer\Helpers\PiiSanitizer;
use Lbose\ErrorAnalyzer\Tests\TestCase;

class PiiSanitizerTest extends TestCase
{
    private PiiSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sanitizer = new PiiSanitizer;
    }

    /** @test */
    public function it_sanitizes_context_to_allowed_keys_only(): void
    {
        $context = [
            'environment' => 'production',
            'timestamp' => '2025-01-31T12:00:00Z',
            'url' => 'https://example.com/api/users',
            'user_id' => '12345',
            'password' => 'secret',
            'api_key' => 'sk_test_123',
        ];

        $sanitized = $this->sanitizer->sanitizeContext($context);

        $this->assertArrayHasKey('environment', $sanitized);
        $this->assertArrayHasKey('timestamp', $sanitized);
        $this->assertArrayHasKey('url', $sanitized);
        $this->assertArrayHasKey('user_id', $sanitized);
        $this->assertArrayNotHasKey('password', $sanitized);
        $this->assertArrayNotHasKey('api_key', $sanitized);
    }

    /** @test */
    public function it_removes_query_parameters_from_url(): void
    {
        $context = [
            'url' => 'https://example.com/api/users?token=secret&id=123',
        ];

        $sanitized = $this->sanitizer->sanitizeContext($context);

        $this->assertEquals('https://example.com/api/users', $sanitized['url']);
    }

    /** @test */
    public function it_masks_email_addresses_in_trace(): void
    {
        $trace = 'Error occurred for user john.doe@example.com in file test.php';

        $sanitized = $this->sanitizer->sanitizeTrace($trace);

        $this->assertStringNotContainsString('john.doe@example.com', $sanitized);
        $this->assertStringContainsString('[EMAIL_MASKED]', $sanitized);
    }

    /** @test */
    public function it_masks_tokens_in_trace(): void
    {
        $trace = 'API call failed with token: a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';

        $sanitized = $this->sanitizer->sanitizeTrace($trace);

        $this->assertStringNotContainsString('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4', $sanitized);
        $this->assertStringContainsString('[TOKEN_MASKED]', $sanitized);
    }

    /** @test */
    public function it_masks_uuids_in_trace(): void
    {
        $trace = 'Error for request: 550e8400-e29b-41d4-a716-446655440000';

        $sanitized = $this->sanitizer->sanitizeTrace($trace);

        $this->assertStringNotContainsString('550e8400-e29b-41d4-a716-446655440000', $sanitized);
        $this->assertStringContainsString('[UUID_MASKED]', $sanitized);
    }

    /** @test */
    public function it_truncates_long_traces(): void
    {
        // Use non-hex characters to avoid TOKEN_MASKED matching
        $longTrace = str_repeat('X', 15000);

        $sanitized = $this->sanitizer->sanitizeTrace($longTrace);

        $this->assertLessThanOrEqual(10050, strlen($sanitized)); // 10000 + "\n... (truncated)"
        $this->assertStringContainsString('... (truncated)', $sanitized);
    }
}
