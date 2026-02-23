<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Tests\Unit;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Lbose\ErrorAnalyzer\IssueTrackers\GithubIssueTracker;
use Lbose\ErrorAnalyzer\Models\ErrorReport;
use Lbose\ErrorAnalyzer\Services\Contracts\IssueTitleGeneratorInterface;
use Lbose\ErrorAnalyzer\Services\IssueTitleGenerators\NullIssueTitleGenerator;
use Lbose\ErrorAnalyzer\Tests\TestCase;
use RuntimeException;

class GithubIssueTrackerTest extends TestCase
{
    private GithubIssueTracker $tracker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tracker = new GithubIssueTracker(new NullIssueTitleGenerator);

        config()->set('error-analyzer.issue_tracker.github.token', 'github-token');
        config()->set('error-analyzer.issue_tracker.github.repository', 'owner/repo');
    }

    public function test_sends_user_agent_header_when_creating_issue(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response([
                'html_url' => 'https://github.com/owner/repo/issues/123',
                'number' => 123,
            ], 201),
        ]);

        $result = $this->tracker->createIssue(
            $this->makeReport(),
            $this->analysis(),
            'trace',
            ['environment' => 'testing'],
        );

        $this->assertSame('created', $result['status']);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.github.com/repos/owner/repo/issues'
                && $request->hasHeader('User-Agent', 'lbose-laravel-error-analyzer')
                && $request->hasHeader('X-GitHub-Api-Version', '2022-11-28');
        });
    }

    public function test_maps_422_to_validation_failed_status(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response([
                'message' => 'Validation Failed',
            ], 422),
        ]);

        $result = $this->tracker->createIssue(
            $this->makeReport(),
            $this->analysis(),
            'trace',
            ['environment' => 'testing'],
        );

        $this->assertSame('validation_failed', $result['status']);
        $this->assertSame('Validation Failed', $result['message']);
    }

    public function test_maps_rate_limit_response_to_rate_limited_status(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response(
                ['message' => 'API rate limit exceeded'],
                403,
                ['X-RateLimit-Remaining' => '0'],
            ),
        ]);

        $result = $this->tracker->createIssue(
            $this->makeReport(),
            $this->analysis(),
            'trace',
            ['environment' => 'testing'],
        );

        $this->assertSame('rate_limited', $result['status']);
    }

    public function test_uses_ai_generated_suffix_when_available(): void
    {
        $tracker = new GithubIssueTracker(new class implements IssueTitleGeneratorInterface
        {
            public function generateTitleSuffix(ErrorReport $report, array $analysis, array $sanitizedContext): ?string
            {
                return "  DB接続設定不備でユーザー一覧取得に失敗  \n";
            }
        });

        Http::fake([
            'api.github.com/*' => Http::response([
                'html_url' => 'https://github.com/owner/repo/issues/123',
                'number' => 123,
            ], 201),
        ]);

        $result = $tracker->createIssue(
            $this->makeReport(),
            $this->analysis(),
            'trace',
            ['environment' => 'testing'],
        );

        $this->assertSame('created', $result['status']);

        Http::assertSent(function (Request $request): bool {
            $title = (string) ($request->data()['title'] ?? '');

            return str_starts_with($title, '[Error][HIGH] RuntimeException: ')
                && str_contains($title, 'DB接続設定不備でユーザー一覧取得に失敗');
        });
    }

    public function test_falls_back_to_rule_based_title_when_ai_generator_returns_null(): void
    {
        $tracker = new GithubIssueTracker(new class implements IssueTitleGeneratorInterface
        {
            public function generateTitleSuffix(ErrorReport $report, array $analysis, array $sanitizedContext): ?string
            {
                return null;
            }
        });

        Http::fake([
            'api.github.com/*' => Http::response([
                'html_url' => 'https://github.com/owner/repo/issues/123',
                'number' => 123,
            ], 201),
        ]);

        $tracker->createIssue($this->makeReport(), $this->analysis(), 'trace', ['environment' => 'testing']);

        Http::assertSent(function (Request $request): bool {
            return (string) ($request->data()['title'] ?? '') === '[Error][HIGH] RuntimeException: Test error message';
        });
    }

    public function test_falls_back_to_rule_based_title_when_ai_generator_throws_exception(): void
    {
        Log::spy();

        $tracker = new GithubIssueTracker(new class implements IssueTitleGeneratorInterface
        {
            public function generateTitleSuffix(ErrorReport $report, array $analysis, array $sanitizedContext): ?string
            {
                throw new RuntimeException('AI title failed');
            }
        });

        Http::fake([
            'api.github.com/*' => Http::response([
                'html_url' => 'https://github.com/owner/repo/issues/123',
                'number' => 123,
            ], 201),
        ]);

        $result = $tracker->createIssue($this->makeReport(), $this->analysis(), 'trace', ['environment' => 'testing']);

        $this->assertSame('created', $result['status']);

        Http::assertSent(function (Request $request): bool {
            return (string) ($request->data()['title'] ?? '') === '[Error][HIGH] RuntimeException: Test error message';
        });
    }

    public function test_limits_final_title_length_when_ai_generated_suffix_is_too_long(): void
    {
        $tracker = new GithubIssueTracker(new class implements IssueTitleGeneratorInterface
        {
            public function generateTitleSuffix(ErrorReport $report, array $analysis, array $sanitizedContext): ?string
            {
                return str_repeat('very-long-title-part ', 20);
            }
        });

        Http::fake([
            'api.github.com/*' => Http::response([
                'html_url' => 'https://github.com/owner/repo/issues/123',
                'number' => 123,
            ], 201),
        ]);

        $tracker->createIssue($this->makeReport(), $this->analysis(), 'trace', ['environment' => 'testing']);

        $capturedTitle = null;
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.github.com/repos/owner/repo/issues';
        });

        Http::assertSent(function (Request $request) use (&$capturedTitle): bool {
            $capturedTitle = (string) ($request->data()['title'] ?? '');

            return true;
        });

        $this->assertNotNull($capturedTitle);
        $this->assertLessThanOrEqual(80, strlen($capturedTitle));
    }

    private function makeReport(): ErrorReport
    {
        $report = new ErrorReport([
            'exception_class' => 'RuntimeException',
            'message' => 'Test error message',
            'file' => '/path/to/file.php',
            'line' => 42,
            'fingerprint' => 'abc123',
            'dedupe_window' => 12345,
            'trace' => 'trace',
            'severity' => 'high',
            'category' => 'other',
            'analysis' => $this->analysis(),
            'context' => ['environment' => 'testing'],
            'occurred_at' => now(),
        ]);
        $report->id = 1;

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function analysis(): array
    {
        return [
            'severity' => 'high',
            'category' => 'other',
            'root_cause' => 'root cause',
            'impact' => 'impact',
            'immediate_action' => 'immediate action',
            'recommended_fix' => 'recommended fix',
            'similar_issues' => [],
            'prevention' => 'prevention',
        ];
    }
}
