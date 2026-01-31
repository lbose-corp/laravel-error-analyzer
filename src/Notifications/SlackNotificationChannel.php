<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Notifications;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Lbose\ErrorAnalyzer\Models\ErrorReport;
use Lbose\ErrorAnalyzer\Services\Contracts\NotificationChannelInterface;
use Throwable;

/**
 * Slack Webhookを使用した通知実装
 */
final class SlackNotificationChannel implements NotificationChannelInterface
{
    /**
     * Slackに通知を送信
     */
    public function notify(ErrorReport $report): void
    {
        if (! $this->shouldNotify($report->severity)) {
            return;
        }

        $webhook = (string) config('error-analyzer.notification.slack.webhook');
        if ($webhook === '') {
            Log::warning('Slack Webhook URLが未設定です。', [
                'error_report_id' => $report->id,
            ]);

            return;
        }

        $payload = $this->buildSlackPayload($report);

        try {
            Http::timeout(10)
                ->post($webhook, $payload)
                ->throw();

            Log::info('Slack通知を送信しました。', [
                'error_report_id' => $report->id,
                'severity' => $report->severity,
            ]);
        } catch (Throwable $e) {
            Log::error('Slack通知の送信に失敗しました。', [
                'error_report_id' => $report->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 指定された重要度で通知すべきか判定
     */
    public function shouldNotify(string $severity): bool
    {
        $minSeverity = (string) config('error-analyzer.notification.slack.min_severity', 'high');
        $severityOrder = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

        $reportSeverityLevel = $severityOrder[$severity] ?? 0;
        $minSeverityLevel = $severityOrder[$minSeverity] ?? 3;

        return $reportSeverityLevel >= $minSeverityLevel;
    }

    /**
     * Slack通知用のペイロードを構築
     *
     * @return array<string, mixed>
     */
    private function buildSlackPayload(ErrorReport $report): array
    {
        $username = (string) config('error-analyzer.notification.slack.username', 'Error Analyzer');
        $icon = (string) config('error-analyzer.notification.slack.icon', ':warning:');
        $channel = (string) config('error-analyzer.notification.slack.channel');

        $rootCause = $report->analysis['root_cause'] ?? 'N/A';
        $impact = $report->analysis['impact'] ?? 'N/A';

        $color = match ($report->severity) {
            'critical' => 'danger',
            'high' => 'warning',
            default => 'good',
        };

        $payload = [
            'username' => $username,
            'icon_emoji' => $icon,
            'text' => '🚨 Critical Error Detected',
            'attachments' => [
                [
                    'color' => $color,
                    'title' => $report->exception_class,
                    'fields' => [
                        [
                            'title' => 'Severity',
                            'value' => strtoupper($report->severity),
                            'short' => true,
                        ],
                        [
                            'title' => 'Category',
                            'value' => $report->category,
                            'short' => true,
                        ],
                        [
                            'title' => 'Root Cause',
                            'value' => $rootCause,
                            'short' => false,
                        ],
                        [
                            'title' => 'Impact',
                            'value' => $impact,
                            'short' => false,
                        ],
                        [
                            'title' => 'File',
                            'value' => $report->file.':'.$report->line,
                            'short' => false,
                        ],
                    ],
                    'footer' => 'Error Analysis System',
                    'ts' => now()->timestamp,
                ],
            ],
        ];

        if ($channel !== '') {
            $payload['channel'] = $channel;
        }

        return $payload;
    }
}
