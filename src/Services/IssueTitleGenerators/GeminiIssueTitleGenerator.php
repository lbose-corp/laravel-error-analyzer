<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Services\IssueTitleGenerators;

use Illuminate\Support\Facades\Log;
use Lbose\ErrorAnalyzer\Models\ErrorReport;
use Lbose\ErrorAnalyzer\Services\Contracts\IssueTitleGeneratorInterface;
use Lbose\ErrorAnalyzer\Services\ErrorAnalysisService;
use RuntimeException;

class GeminiIssueTitleGenerator implements IssueTitleGeneratorInterface
{
    public function __construct(
        private readonly ErrorAnalysisService $errorAnalysisService,
    ) {}

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $sanitizedContext
     */
    public function generateTitleSuffix(
        ErrorReport $report,
        array $analysis,
        array $sanitizedContext,
    ): ?string {
        if (! $this->errorAnalysisService->tryIncrementIfAllowed()) {
            Log::info('AIタイトル生成をスキップしました（クォータ上限）。', [
                'error_report_id' => $report->id,
                'quota_scope' => 'analysis.daily_limit',
                'fallback' => true,
            ]);

            return null;
        }

        $this->assertGeminiDependencyIsInstalled();

        $model = (string) config('error-analyzer.issue_tracker.github.ai_title.model', 'gemini-2.5-flash-lite');
        $prompt = $this->buildPrompt($report, $analysis, $sanitizedContext);

        /** @phpstan-ignore-next-line - Gemini facade is an optional dependency */
        $response = \Gemini\Laravel\Facades\Gemini::generativeModel(model: $model)->generateContent($prompt);
        $text = trim((string) $response->text());

        if ($text === '') {
            throw new RuntimeException('Issueタイトル生成AI応答が空でした。');
        }

        $normalized = $this->normalizeSingleLineText($text);

        if ($normalized === '') {
            throw new RuntimeException('Issueタイトル生成AI応答の形式が不正です。');
        }

        return $normalized;
    }

    protected function assertGeminiDependencyIsInstalled(): void
    {
        if (class_exists(\Gemini\Laravel\Facades\Gemini::class)) {
            return;
        }

        throw new RuntimeException(
            'Gemini issue title generatorを利用するには `google-gemini-php/laravel` をインストールしてください。',
        );
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $sanitizedContext
     */
    private function buildPrompt(ErrorReport $report, array $analysis, array $sanitizedContext): string
    {
        $exceptionClass = class_basename($report->exception_class);
        $severity = (string) ($report->severity ?: 'medium');
        $category = (string) ($report->category ?: 'other');
        $message = (string) $report->message;
        $rootCause = (string) ($analysis['root_cause'] ?? '');
        $impact = (string) ($analysis['impact'] ?? '');
        $requestUrl = (string) ($sanitizedContext['url'] ?? 'N/A');
        $languageInstruction = $this->containsJapanese($message)
            ? '日本語で、簡潔かつ具体的に'
            : 'Use concise and specific English';

        return <<<PROMPT
あなたはLaravelエラーのIssueタイトル作成支援です。
GitHub Issueタイトルの「prefix以降の要約部分」だけを生成してください。

要件:
- {$languageInstruction}
- 1行のみ
- Markdown・コードブロック・引用符は不要
- 具体的な症状/原因が伝わる短い表現
- 個人情報は含めない

入力:
- Severity: {$severity}
- Category: {$category}
- Exception: {$exceptionClass}
- Message: {$message}
- Root cause: {$rootCause}
- Impact: {$impact}
- Location: {$report->file}:{$report->line}
- URL: {$requestUrl}

出力は要約テキスト1行のみ。
PROMPT;
    }

    private function normalizeSingleLineText(string $text): string
    {
        $normalized = str_replace('```', ' ', $text);
        $normalized = (string) preg_replace('/\s+/u', ' ', $normalized);

        return trim($normalized, " \t\n\r\0\x0B\"'`");
    }

    private function containsJapanese(string $text): bool
    {
        return preg_match('/[\p{Hiragana}\p{Katakana}\p{Han}]/u', $text) === 1;
    }
}
