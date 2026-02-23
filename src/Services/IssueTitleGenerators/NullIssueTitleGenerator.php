<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Services\IssueTitleGenerators;

use Lbose\ErrorAnalyzer\Models\ErrorReport;
use Lbose\ErrorAnalyzer\Services\Contracts\IssueTitleGeneratorInterface;

final class NullIssueTitleGenerator implements IssueTitleGeneratorInterface
{
    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $sanitizedContext
     */
    public function generateTitleSuffix(
        ErrorReport $report,
        array $analysis,
        array $sanitizedContext,
    ): ?string {
        return null;
    }
}
