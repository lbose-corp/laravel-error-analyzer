<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\IssueTrackers;

use Lbose\ErrorAnalyzer\Models\ErrorReport;
use Lbose\ErrorAnalyzer\Services\Contracts\IssueTrackerInterface;

/**
 * Issue作成を行わないNull実装
 */
final class NullIssueTracker implements IssueTrackerInterface
{
    /**
     * Issue作成をスキップ
     *
     * @param  array{severity: string, category: string, root_cause: string, impact: string, solution: string, related_code: string}  $analysis
     * @param  array<string, mixed>  $sanitizedContext
     * @return array{status: string}
     */
    public function createIssue(
        ErrorReport $report,
        array $analysis,
        string $sanitizedTrace,
        array $sanitizedContext,
    ): array {
        return [
            'status' => 'disabled',
        ];
    }
}
