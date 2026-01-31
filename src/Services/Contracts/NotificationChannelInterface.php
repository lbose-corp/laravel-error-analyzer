<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Services\Contracts;

use Lbose\ErrorAnalyzer\Models\ErrorReport;

interface NotificationChannelInterface
{
    /**
     * Send a notification for an error report.
     *
     * @param  ErrorReport  $report  The error report to notify about
     */
    public function notify(ErrorReport $report): void;

    /**
     * Determine if a notification should be sent based on severity.
     *
     * @param  string  $severity  Error severity level
     */
    public function shouldNotify(string $severity): bool;
}
