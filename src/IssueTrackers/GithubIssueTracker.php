<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\IssueTrackers;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Lbose\ErrorAnalyzer\Models\ErrorReport;
use Lbose\ErrorAnalyzer\Services\Contracts\IssueTitleGeneratorInterface;
use Lbose\ErrorAnalyzer\Services\Contracts\IssueTrackerInterface;
use Lbose\ErrorAnalyzer\Services\IssueTitleGenerators\NullIssueTitleGenerator;
use Throwable;

/**
 * GitHub REST APIを使用したIssue作成実装
 */
final class GithubIssueTracker implements IssueTrackerInterface
{
    private const API_BASE = 'https://api.github.com';

    private const REQUEST_TIMEOUT_SECONDS = 10;

    private const MAX_TITLE_LENGTH = 80;

    public function __construct(
        ?IssueTitleGeneratorInterface $issueTitleGenerator = null,
    ) {
        $this->issueTitleGenerator = $issueTitleGenerator ?? new NullIssueTitleGenerator;
    }

    private readonly IssueTitleGeneratorInterface $issueTitleGenerator;

    /**
     * GitHub Issueを作成
     *
     * @param  array{severity: string, category: string, root_cause: string, impact: string, immediate_action?: string, recommended_fix?: string, similar_issues?: array<int, string>, prevention?: string}  $analysis
     * @param  array<string, mixed>  $sanitizedContext
     * @return array{status: string, url?: string, number?: int, message?: string}
     */
    public function createIssue(
        ErrorReport $report,
        array $analysis,
        string $sanitizedTrace,
        array $sanitizedContext,
    ): array {
        $token = (string) config('error-analyzer.issue_tracker.github.token');
        $repository = (string) config('error-analyzer.issue_tracker.github.repository');

        if ($token === '' || $repository === '') {
            $message = 'GitHub Issue連携が有効ですが、token/repositoryが未設定です。';
            Log::warning($message, [
                'error_report_id' => $report->id,
                'has_token' => $token !== '',
                'repository' => $repository,
            ]);

            return [
                'status' => 'missing_config',
                'message' => $message,
            ];
        }

        $payload = $this->buildCreateIssuePayload($report, $analysis, $sanitizedTrace, $sanitizedContext);

        try {
            $response = $this->sendCreateIssueRequest($token, $repository, $payload);

            return $this->buildCreatedIssueResult($response);
        } catch (RequestException $e) {
            return $this->handleRequestException($report, $e);
        } catch (Throwable $e) {
            return $this->handleUnexpectedException($report, $e);
        }
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $sanitizedContext
     * @return array<string, mixed>
     */
    private function buildCreateIssuePayload(
        ErrorReport $report,
        array $analysis,
        string $sanitizedTrace,
        array $sanitizedContext,
    ): array {
        $payload = [
            'title' => $this->buildIssueTitle($report, $analysis, $sanitizedContext),
            'body' => $this->buildIssueBody($report, $analysis, $sanitizedTrace, $sanitizedContext),
        ];

        $labels = $this->normalizeStringList(config('error-analyzer.issue_tracker.github.labels', []));
        if ($labels !== []) {
            $payload['labels'] = $labels;
        }

        $assignees = $this->normalizeStringList(config('error-analyzer.issue_tracker.github.assignees', []));
        if ($assignees !== []) {
            $payload['assignees'] = $assignees;
        }

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $item): string => trim((string) $item),
                $value,
            ),
            static fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendCreateIssueRequest(string $token, string $repository, array $payload): Response
    {
        return Http::withToken($token)
            ->acceptJson()
            ->timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->withHeaders([
                'User-Agent' => 'lbose-laravel-error-analyzer',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
            ->post(sprintf('%s/repos/%s/issues', self::API_BASE, $repository), $payload)
            ->throw();
    }

    /**
     * @return array{status: string, url?: string, number?: int}
     */
    private function buildCreatedIssueResult(Response $response): array
    {
        $data = $response->json();
        $issueUrl = is_array($data) ? ($data['html_url'] ?? null) : null;
        $issueNumber = is_array($data) ? ($data['number'] ?? null) : null;

        return [
            'status' => 'created',
            'url' => $issueUrl,
            'number' => $issueNumber,
        ];
    }

    /**
     * @return array{status: string, message: string}
     */
    private function handleRequestException(ErrorReport $report, RequestException $exception): array
    {
        $response = $exception->response;
        $status = $this->mapGithubErrorStatus($response);
        $message = $this->extractGithubErrorMessage($response, $exception->getMessage());

        Log::error('GitHub Issue連携に失敗しました。', [
            'error_report_id' => $report->id,
            'exception' => $exception::class,
            'http_status' => $response->status(),
            'status' => $status,
            'message' => $message,
        ]);

        return [
            'status' => $status,
            'message' => $message,
        ];
    }

    /**
     * @return array{status: string, message: string}
     */
    private function handleUnexpectedException(ErrorReport $report, Throwable $exception): array
    {
        Log::error('GitHub Issue連携に失敗しました。', [
            'error_report_id' => $report->id,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);

        return [
            'status' => 'request_failed',
            'message' => $exception->getMessage(),
        ];
    }

    private function mapGithubErrorStatus(?Response $response): string
    {
        if ($response === null) {
            return 'request_failed';
        }

        $statusCode = $response->status();

        if ($statusCode === 403 && $response->header('X-RateLimit-Remaining') === '0') {
            return 'rate_limited';
        }

        return match ($statusCode) {
            401 => 'unauthorized',
            403 => 'forbidden',
            404 => 'repository_not_found',
            422 => 'validation_failed',
            default => 'request_failed',
        };
    }

    private function extractGithubErrorMessage(?Response $response, string $fallback): string
    {
        if ($response === null) {
            return $fallback;
        }

        $data = $response->json();
        if (is_array($data) && isset($data['message']) && is_string($data['message'])) {
            return $data['message'];
        }

        return $fallback;
    }

    /**
     * GitHub Issueのタイトルを構築
     */
    private function buildIssueTitle(ErrorReport $report, array $analysis, array $sanitizedContext): string
    {
        $fallbackTitle = $this->buildRuleBasedIssueTitle($report);

        try {
            $generatedSuffix = $this->issueTitleGenerator->generateTitleSuffix($report, $analysis, $sanitizedContext);
        } catch (Throwable $e) {
            Log::warning('AI Issueタイトル生成に失敗したため既存タイトルへフォールバックしました。', [
                'error_report_id' => $report->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'fallback' => true,
            ]);

            return $fallbackTitle;
        }

        if ($generatedSuffix === null) {
            return $fallbackTitle;
        }

        $prefix = $this->buildIssueTitlePrefix($report);
        $maxSuffixLength = max(10, self::MAX_TITLE_LENGTH - Str::length($prefix));
        $normalizedSuffix = $this->normalizeIssueTitleSuffix($generatedSuffix, $maxSuffixLength);

        if ($normalizedSuffix === null) {
            return $fallbackTitle;
        }

        return $prefix.$normalizedSuffix;
    }

    private function buildRuleBasedIssueTitle(ErrorReport $report): string
    {
        $prefix = $this->buildIssueTitlePrefix($report);
        $maxMessageLength = max(10, self::MAX_TITLE_LENGTH - Str::length($prefix));
        $normalizedMessage = (string) preg_replace('/\s+/u', ' ', $report->message);
        $trimmedMessage = $this->truncateTitlePart(trim($normalizedMessage), $maxMessageLength);

        return $prefix.$trimmedMessage;
    }

    private function buildIssueTitlePrefix(ErrorReport $report): string
    {
        $exceptionClass = class_basename($report->exception_class);
        $trimmedExceptionClass = Str::limit($exceptionClass, 30, '...');

        return sprintf('[Error][%s] %s: ', strtoupper($report->severity), $trimmedExceptionClass);
    }

    private function normalizeIssueTitleSuffix(string $suffix, int $maxLength): ?string
    {
        $normalized = (string) preg_replace('/\s+/u', ' ', $suffix);
        $normalized = trim($normalized, " \t\n\r\0\x0B\"'`");

        if ($normalized === '') {
            return null;
        }

        $limited = $this->truncateTitlePart($normalized, $maxLength);

        return $limited === '' ? null : $limited;
    }

    private function truncateTitlePart(string $value, int $maxLength): string
    {
        if (Str::length($value) <= $maxLength) {
            return $value;
        }

        if ($maxLength <= 3) {
            return Str::substr('...', 0, $maxLength);
        }

        return Str::substr($value, 0, $maxLength - 3).'...';
    }

    /**
     * GitHub Issueの本文を構築
     *
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $sanitizedContext
     */
    private function buildIssueBody(
        ErrorReport $report,
        array $analysis,
        string $sanitizedTrace,
        array $sanitizedContext,
    ): string {
        $contextJson = $this->formatContextJson($sanitizedContext);
        $similarIssueLines = $this->formatSimilarIssues($analysis['similar_issues'] ?? []);

        $requestUrl = $sanitizedContext['url'] ?? 'N/A';
        $userId = $sanitizedContext['user_id'] ?? 'guest';
        $environment = $sanitizedContext['environment'] ?? 'N/A';
        $rootCause = $analysis['root_cause'] ?? 'N/A';
        $impact = $analysis['impact'] ?? 'N/A';
        $immediateAction = $analysis['immediate_action'] ?? 'N/A';
        $recommendedFix = $analysis['recommended_fix'] ?? 'N/A';
        $prevention = $analysis['prevention'] ?? 'N/A';

        return <<<BODY
## 概要
- エラーレポートID: {$report->id}
- 重要度: {$report->severity}
- カテゴリ: {$report->category}
- 例外クラス: {$report->exception_class}
- メッセージ: {$report->message}
- 発生箇所: {$report->file}:{$report->line}
- 発生日時: {$report->occurred_at->toIso8601String()}
- フィンガープリント: {$report->fingerprint}
- リクエストURL: {$requestUrl}
- ユーザーID: {$userId}
- 環境: {$environment}

## AI解析結果
- 根本原因: {$rootCause}
- 影響: {$impact}
- 即時対応: {$immediateAction}
- 推奨修正: {$recommendedFix}
- 再発防止: {$prevention}
- 類似情報:
{$similarIssueLines}

## スタックトレース
```
{$sanitizedTrace}
```

## コンテキスト
```json
{$contextJson}
```
BODY;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function formatContextJson(array $context): string
    {
        $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if ($contextJson === false) {
            return '{}';
        }

        return $contextJson;
    }

    private function formatSimilarIssues(mixed $similarIssues): string
    {
        if (! is_array($similarIssues) || $similarIssues === []) {
            return '- N/A';
        }

        return implode("\n", array_map(
            static fn (mixed $item): string => '- '.(string) $item,
            $similarIssues,
        ));
    }
}
