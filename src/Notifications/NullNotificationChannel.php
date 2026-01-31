<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Notifications;

use Lbose\ErrorAnalyzer\Models\ErrorReport;
use Lbose\ErrorAnalyzer\Services\Contracts\NotificationChannelInterface;

/**
 * 通知を行わないNull実装
 */
final class NullNotificationChannel implements NotificationChannelInterface
{
    /**
     * 通知をスキップ
     */
    public function notify(ErrorReport $report): void
    {
        // 何もしない
    }

    /**
     * 常にfalseを返す（通知しない）
     */
    public function shouldNotify(string $severity): bool
    {
        return false;
    }
}
