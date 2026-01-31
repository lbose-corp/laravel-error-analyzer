<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\IssueTrackers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Lbose\ErrorAnalyzer\Models\ErrorReport;
use Lbose\ErrorAnalyzer\Services\Contracts\IssueTrackerInterface;
use Throwable;

/**
 * GitHub REST APIを使用したIssue作成実装
 */
final class GithubIssueTracker implements IssueTrackerInterface
{
    /**
     * GitHub Issueを作成
     *
     * @param  array{severity: string, category: string, root_cause: string, impact: string, solution: string, related_code: string}  $analysis
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
        $apiBase = 'https://api.github.com';
        $timeout = 10;

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

        $labels = config('error-analyzer.issue_tracker.github.labels', []);
        if (! is_array($labels)) {
            $labels = [];
        }
        $labels = array_values(array_filter(
            array_map('trim', $labels),
            static fn (string $label): bool => $label !== '',
        ));

        $payload = [
            'title' => $this->buildIssueTitle($report),
            'body' => $this->buildIssueBody($report, $analysis, $sanitizedTrace, $sanitizedContext),
        ];

        if ($labels !== []) {
            $payload['labels'] = $labels;
        }

        $assignees = config('error-analyzer.issue_tracker.github.assignees', []);
        if (is_array($assignees) && $assignees !== []) {
            $assignees = array_values(array_filter(
                array_map('trim', $assignees),
                static fn (string $assignee): bool => $assignee !== '',
            ));
            if ($assignees !== []) {
                $payload['assignees'] = $assignees;
            }
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout($timeout)
                ->withHeaders([
                    'X-GitHub-Api-Version' => '2022-11-28',
                ])
                ->post(sprintf('%s/repos/%s/issues', $apiBase, $repository), $payload)
                ->throw();

            $data = $response->json();
            $issueUrl = is_array($data) ? ($data['html_url'] ?? null) : null;
            $issueNumber = is_array($data) ? ($data['number'] ?? null) : null;

            return [
                'status' => 'created',
                'url' => $issueUrl,
                'number' => $issueNumber,
            ];
        } catch (Throwable $e) {
            Log::error('GitHub Issue連携に失敗しました。', [
                'error_report_id' => $report->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [
                'status' => 'request_failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * GitHub Issueのタイトルを構築
     */
    private function buildIssueTitle(ErrorReport $report): string
    {
        $maxTitleLength = 80;

        // 例外クラス名を短縮（名前空間を除去）
        $exceptionClass = class_basename($report->exception_class);
        $trimmedExceptionClass = Str::limit($exceptionClass, 30, '...');

        $message = (string) preg_replace('/\s+/', ' ', $report->message);
        $prefix = sprintf('[Error][%s] %s: ', strtoupper($report->severity), $trimmedExceptionClass);
        $maxMessageLength = max(10, $maxTitleLength - Str::length($prefix));
        $trimmedMessage = Str::limit($message, $maxMessageLength, '...');

        return $prefix.$trimmedMessage;
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
        $contextJson = json_encode($sanitizedContext, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($contextJson === false) {
            $contextJson = '{}';
        }

        $similarIssues = $analysis['similar_issues'] ?? [];
        $similarIssueLines = '- N/A';
        if (is_array($similarIssues) && $similarIssues !== []) {
            $similarIssueLines = implode("\n", array_map(
                static fn ($item): string => '- '.(string) $item,
                $similarIssues,
            ));
        }

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
}
