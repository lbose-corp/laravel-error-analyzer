<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Services\Contracts;

use Lbose\ErrorAnalyzer\Models\ErrorReport;

/**
 * @phpstan-type IssueResult array{status: string, url?: string, number?: int, message?: string}
 */
interface IssueTrackerInterface
{
    /**
     * Create an issue for an error report.
     *
     * @param  ErrorReport  $report  The error report
     * @param  array<string, mixed>  $analysis  Analysis results from AI
     * @param  string  $sanitizedTrace  Sanitized stack trace
     * @param  array<string, mixed>  $sanitizedContext  Sanitized context information
     * @return array{status: string, url?: string, number?: int, message?: string} Issue creation result
     */
    public function createIssue(
        ErrorReport $report,
        array $analysis,
        string $sanitizedTrace,
        array $sanitizedContext,
    ): array;
}
