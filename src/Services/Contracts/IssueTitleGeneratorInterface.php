<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Services\Contracts;

use Lbose\ErrorAnalyzer\Models\ErrorReport;

interface IssueTitleGeneratorInterface
{
    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $sanitizedContext
     */
    public function generateTitleSuffix(
        ErrorReport $report,
        array $analysis,
        array $sanitizedContext,
    ): ?string;
}
