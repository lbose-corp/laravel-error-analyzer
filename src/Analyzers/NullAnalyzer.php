<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Analyzers;

use Lbose\ErrorAnalyzer\Services\Contracts\AiAnalyzerInterface;

/**
 * AI分析を行わないNull実装
 *
 * @phpstan-import-type AnalysisResult from AiAnalyzerInterface
 */
final class NullAnalyzer implements AiAnalyzerInterface
{
    /**
     * プレースホルダーのみを返す（AI分析なし）
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
        return [
            'severity' => 'medium',
            'category' => 'other',
            'root_cause' => 'AI分析が無効化されています',
            'impact' => 'N/A',
            'immediate_action' => 'AI分析を有効にするには、ERROR_ANALYZER_DRIVER=gemini を設定してください。',
            'recommended_fix' => 'config/error-analyzer.php で analyzer.driver を gemini に設定し、Gemini API キーを設定してください。',
            'similar_issues' => [],
            'prevention' => 'N/A',
        ];
    }
}
