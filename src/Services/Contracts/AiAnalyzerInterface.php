<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Services\Contracts;

/**
 * @phpstan-type AnalysisResult array{
 *     severity: string,
 *     category: string,
 *     root_cause: string,
 *     impact: string,
 *     immediate_action: string,
 *     recommended_fix: string,
 *     similar_issues: array<int, string>,
 *     prevention: string
 * }
 */
interface AiAnalyzerInterface
{
    /**
     * Analyze an error using AI.
     *
     * @param  string  $exceptionClass  Full class name of the exception
     * @param  string  $message  Exception message
     * @param  string  $file  File where the exception occurred
     * @param  int  $line  Line number where the exception occurred
     * @param  string  $sanitizedTrace  Sanitized stack trace
     * @param  array<string, mixed>  $sanitizedContext  Sanitized context information
     * @return array<string, mixed> Analysis results
     */
    public function analyze(
        string $exceptionClass,
        string $message,
        string $file,
        int $line,
        string $sanitizedTrace,
        array $sanitizedContext,
    ): array;
}
