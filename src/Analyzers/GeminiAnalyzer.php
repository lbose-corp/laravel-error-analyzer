<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Analyzers;

use JsonException;
use Lbose\ErrorAnalyzer\Helpers\JsonExtractor;
use Lbose\ErrorAnalyzer\Services\Contracts\AiAnalyzerInterface;
use RuntimeException;

/**
 * Google Gemini AIを使用したエラー分析実装
 *
 * @phpstan-import-type AnalysisResult from AiAnalyzerInterface
 */
final class GeminiAnalyzer implements AiAnalyzerInterface
{
    /**
     * Gemini AIを使用してエラーを分析
     *
     * @param  array<string, mixed>  $sanitizedContext
     * @return array<string, mixed>
     */
    public function analyze(
        string $exceptionClass,
        string $message,
        string $file,
        int $line,
        string $sanitizedTrace,
        array $sanitizedContext,
    ): array {
        $this->assertGeminiDependencyIsInstalled();

        $prompt = $this->buildAnalysisPrompt(
            $exceptionClass,
            $message,
            $file,
            $line,
            $sanitizedTrace,
            $sanitizedContext,
        );

        $model = (string) config('error-analyzer.analyzer.gemini.model', 'gemini-2.5-flash');

        /** @phpstan-ignore-next-line - Gemini facade is an optional dependency */
        $response = \Gemini\Laravel\Facades\Gemini::generativeModel(model: $model)->generateContent($prompt);

        $text = trim((string) $response->text());

        if ($text === '') {
            throw new RuntimeException('AI応答が空でした。');
        }

        $jsonText = JsonExtractor::extract($text);

        try {
            $analysis = json_decode($jsonText, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('AI応答のパースに失敗しました。', previous: $e);
        }

        if (! is_array($analysis)) {
            throw new RuntimeException('AI応答の形式が不正です。');
        }

        return $analysis;
    }

    private function assertGeminiDependencyIsInstalled(): void
    {
        if (class_exists(\Gemini\Laravel\Facades\Gemini::class)) {
            return;
        }

        throw new RuntimeException(
            'Gemini analyzerを利用するには `google-gemini-php/laravel` をインストールしてください。',
        );
    }

    /**
     * AI分析用のプロンプトを構築
     *
     * @param  array<string, mixed>  $sanitizedContext
     */
    private function buildAnalysisPrompt(
        string $exceptionClass,
        string $message,
        string $file,
        int $line,
        string $sanitizedTrace,
        array $sanitizedContext,
    ): string {
        $requestUrl = $sanitizedContext['url'] ?? 'N/A';
        $userId = $sanitizedContext['user_id'] ?? 'guest';
        $appVersion = app()->version();
        $phpVersion = PHP_VERSION;

        return <<<PROMPT
あなたはLaravelアプリケーションのエラー分析の専門家です。
以下のエラー情報を分析し、必ず純粋なJSON形式（コードブロックなし）で回答してください。

【エラー情報】
- 例外クラス: {$exceptionClass}
- メッセージ: {$message}
- ファイル: {$file}:{$line}
- スタックトレース:
{$sanitizedTrace}

【環境情報】
- Laravel バージョン: {$appVersion}
- PHP バージョン: {$phpVersion}
- リクエストURL: {$requestUrl}
- ユーザーID: {$userId}

以下の形式で純粋なJSONオブジェクトのみを返してください（コードブロックは不要）:

{
  "severity": "critical|high|medium|low",
  "category": "database|api|authentication|authorization|validation|performance|network|other",
  "root_cause": "根本原因の簡潔な説明（日本語、100文字以内）",
  "impact": "ユーザーやシステムへの影響（日本語、100文字以内）",
  "immediate_action": "即座に取るべき対応（日本語、200文字以内）",
  "recommended_fix": "推奨される恒久的な修正方法（日本語、具体的なコード例も含む、500文字以内）",
  "similar_issues": ["類似の既知の問題や関連するLaravelドキュメント"],
  "prevention": "今後同様のエラーを防ぐための方法（日本語、200文字以内）"
}

重要: 必ず純粋なJSON形式で回答し、マークダウンのコードブロック記号（```）は含めないでください。
PROMPT;
    }
}
