<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lbose\ErrorAnalyzer\Helpers\FingerprintCalculator;
use Lbose\ErrorAnalyzer\Helpers\PiiSanitizer;
use Lbose\ErrorAnalyzer\Models\ErrorReport;
use Lbose\ErrorAnalyzer\Services\Contracts\AiAnalyzerInterface;
use Lbose\ErrorAnalyzer\Services\Contracts\IssueTrackerInterface;
use Lbose\ErrorAnalyzer\Services\Contracts\NotificationChannelInterface;
use Throwable;

/**
 * エラー分析ジョブ
 *
 * AI分析、Issue作成、Slack通知を行う
 */
class AnalyzeErrorJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * @var int[]
     */
    public array $backoff = [5, 10, 20];

    private string $exceptionClass;

    private string $message;

    private string $file;

    private int $line;

    private string $trace;

    /**
     * @var array<string, mixed>
     */
    private array $context;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(Throwable $exception, array $context = [])
    {
        $this->exceptionClass = $exception::class;
        $this->message = $exception->getMessage();
        $this->file = $exception->getFile();
        $this->line = $exception->getLine();
        $this->trace = $exception->getTraceAsString();
        $this->context = $context;
    }

    public function handle(
        AiAnalyzerInterface $analyzer,
        IssueTrackerInterface $issueTracker,
        NotificationChannelInterface $notificationChannel,
        FingerprintCalculator $fingerprintCalculator,
        PiiSanitizer $piiSanitizer,
    ): void {
        // PIIサニタイズ
        $sanitizedContext = $piiSanitizer->sanitizeContext($this->context);
        $sanitizedTrace = $piiSanitizer->sanitizeTrace($this->trace);

        // fingerprintとdedupe_windowを計算
        $fingerprint = $fingerprintCalculator->compute($this->exceptionClass, $this->file, $this->line);
        $dedupeWindow = $fingerprintCalculator->computeDedupeWindow(
            now()->timestamp,
            (int) config('error-analyzer.analysis.dedupe_window_minutes', 5),
        );

        $storageDriver = (string) config('error-analyzer.storage.driver', 'database');
        $placeholderAttributes = $this->buildPlaceholderReportAttributes(
            $fingerprint,
            $dedupeWindow,
            $sanitizedTrace,
            $sanitizedContext,
        );

        // ストレージドライバーに応じて処理を分岐
        if ($storageDriver === 'database') {
            $report = $this->createDatabasePlaceholderReport($placeholderAttributes, $fingerprint);
            if ($report === null) {
                return;
            }
        } else {
            if (! $this->reserveCacheDedupe($fingerprint, $dedupeWindow)) {
                return;
            }

            $report = $this->createTransientPlaceholderReport($placeholderAttributes);
        }

        // AI解析を実行（失敗時は保存して終了）
        try {
            $analysis = $analyzer->analyze(
                $this->exceptionClass,
                $this->message,
                $this->file,
                $this->line,
                $sanitizedTrace,
                $sanitizedContext,
            );

            $this->applyAnalysisResultToReport($report, $analysis);
            $this->persistReportIfDatabase($report, $storageDriver, [
                'severity' => $report->severity,
                'category' => $report->category,
                'analysis' => $report->analysis,
            ]);

            // 通知送信
            $notificationChannel->notify($report);

            // Issue作成
            $issueResult = $issueTracker->createIssue($report, $analysis, $sanitizedTrace, $sanitizedContext);
            if ($issueResult['status'] !== 'disabled') {
                $this->appendIssueResultToReportAnalysis($report, $issueResult);
                $this->persistReportIfDatabase($report, $storageDriver, ['analysis' => $report->analysis]);
            }
        } catch (Throwable $e) {
            // AI失敗時は失敗内容を保存して終了（リトライでAI再実行が増えない）
            $reportId = $storageDriver === 'database' ? $report->id : null;
            Log::error('AIエラー分析に失敗しました。', [
                'error_report_id' => $reportId,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            $this->applyFailureAnalysisToReport($report, $e);
            $this->persistReportIfDatabase($report, $storageDriver, [
                'severity' => $report->severity,
                'category' => $report->category,
                'analysis' => $report->analysis,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $sanitizedContext
     * @return array<string, mixed>
     */
    private function buildPlaceholderReportAttributes(
        string $fingerprint,
        int $dedupeWindow,
        string $sanitizedTrace,
        array $sanitizedContext,
    ): array {
        return [
            'exception_class' => $this->exceptionClass,
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'fingerprint' => $fingerprint,
            'dedupe_window' => $dedupeWindow,
            'trace' => $sanitizedTrace,
            'severity' => 'medium', // プレースホルダ
            'category' => 'other', // プレースホルダ
            'analysis' => ['status' => 'processing'],
            'context' => $sanitizedContext,
            'occurred_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createDatabasePlaceholderReport(array $attributes, string $fingerprint): ?ErrorReport
    {
        try {
            return ErrorReport::create($attributes);
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                $this->logDuplicateSkip($fingerprint);

                return null;
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createTransientPlaceholderReport(array $attributes): ErrorReport
    {
        $report = new ErrorReport;
        $report->forceFill($attributes);

        return $report;
    }

    private function reserveCacheDedupe(string $fingerprint, int $dedupeWindow): bool
    {
        $dedupeKey = sprintf('error_analyzer:dedupe:%s:%d', $fingerprint, $dedupeWindow);
        $dedupeWindowMinutes = (int) config('error-analyzer.analysis.dedupe_window_minutes', 5);
        $ttl = ($dedupeWindowMinutes * 60) + 60; // ウィンドウ境界のズレ吸収のため+60秒

        if (Cache::add($dedupeKey, true, $ttl)) {
            return true;
        }

        $this->logDuplicateSkip($fingerprint);

        return false;
    }

    private function logDuplicateSkip(string $fingerprint): void
    {
        Log::info('同一エラーが最近分析済みのためスキップしました。', [
            'exception' => $this->exceptionClass,
            'file' => $this->file,
            'line' => $this->line,
            'fingerprint' => $fingerprint,
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function persistReportIfDatabase(ErrorReport $report, string $storageDriver, array $values): void
    {
        if ($storageDriver !== 'database') {
            return;
        }

        $report->update($values);
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private function applyAnalysisResultToReport(ErrorReport $report, array $analysis): void
    {
        $report->severity = $analysis['severity'] ?? 'medium';
        $report->category = $analysis['category'] ?? 'other';
        $report->analysis = $analysis;
    }

    private function applyFailureAnalysisToReport(ErrorReport $report, Throwable $exception): void
    {
        $report->severity = 'medium';
        $report->category = 'other';
        $report->analysis = [
            'status' => 'failed',
            'ai_error' => [
                'message' => $exception->getMessage(),
                'exception_class' => $exception::class,
                'occurred_at' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $issueResult
     */
    private function appendIssueResultToReportAnalysis(ErrorReport $report, array $issueResult): void
    {
        $updatedAnalysis = $report->analysis;
        $updatedAnalysis['github_issue'] = $issueResult;
        $report->analysis = $updatedAnalysis;
    }

    /**
     * ユニーク制約違反かどうかを判定する（DBドライバー固有のエラーコードをチェック）
     */
    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $driverName = DB::connection()->getDriverName();
        $errorCode = $exception->getCode();

        return match ($driverName) {
            'mysql', 'mariadb', 'pgsql' => $errorCode === '23000',
            'sqlite' => in_array($errorCode, ['19', '23000'], true),
            'sqlsrv' => $errorCode === '2627',
            default => $errorCode === '23000',
        };
    }
}
