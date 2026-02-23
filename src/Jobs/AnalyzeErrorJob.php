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

        // ストレージドライバーに応じて処理を分岐
        if ($storageDriver === 'database') {
            // DB保存モード: プレースホルダのErrorReportを先にinsert（原子的な予約）
            try {
                $report = ErrorReport::create([
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
                ]);
            } catch (QueryException $e) {
                // ユニーク制約違反 = 直近に同一分析が走っている/走った
                if ($this->isUniqueConstraintViolation($e)) {
                    Log::info('同一エラーが最近分析済みのためスキップしました。', [
                        'exception' => $this->exceptionClass,
                        'file' => $this->file,
                        'line' => $this->line,
                        'fingerprint' => $fingerprint,
                    ]);

                    return;
                }

                throw $e;
            }
        } else {
            // DB保存OFFモード: Cacheでdedupe
            $dedupeKey = sprintf('error_analyzer:dedupe:%s:%d', $fingerprint, $dedupeWindow);
            $dedupeWindowMinutes = (int) config('error-analyzer.analysis.dedupe_window_minutes', 5);
            $ttl = ($dedupeWindowMinutes * 60) + 60; // ウィンドウ境界のズレ吸収のため+60秒

            // Cache::add は既にキーが存在する場合は false を返す（原子的なチェック）
            if (! Cache::add($dedupeKey, true, $ttl)) {
                Log::info('同一エラーが最近分析済みのためスキップしました。', [
                    'exception' => $this->exceptionClass,
                    'file' => $this->file,
                    'line' => $this->line,
                    'fingerprint' => $fingerprint,
                ]);

                return;
            }

            // インメモリのErrorReportを作成（DB保存はしない）
            $report = new ErrorReport();
            $report->forceFill([
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
            ]);
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

            // 解析結果を反映
            $report->severity = $analysis['severity'] ?? 'medium';
            $report->category = $analysis['category'] ?? 'other';
            $report->analysis = $analysis;

            // DB保存モードの場合はDBに保存
            if ($storageDriver === 'database') {
                $report->update([
                    'severity' => $report->severity,
                    'category' => $report->category,
                    'analysis' => $report->analysis,
                ]);
            }

            // 通知送信
            $notificationChannel->notify($report);

            // Issue作成
            $issueResult = $issueTracker->createIssue($report, $analysis, $sanitizedTrace, $sanitizedContext);
            if ($issueResult['status'] !== 'disabled') {
                $updatedAnalysis = $report->analysis;
                $updatedAnalysis['github_issue'] = $issueResult;
                $report->analysis = $updatedAnalysis;

                // DB保存モードの場合はDBに保存
                if ($storageDriver === 'database') {
                    $report->update(['analysis' => $report->analysis]);
                }
            }
        } catch (Throwable $e) {
            // AI失敗時は失敗内容を保存して終了（リトライでAI再実行が増えない）
            $reportId = $storageDriver === 'database' ? $report->id : null;
            Log::error('AIエラー分析に失敗しました。', [
                'error_report_id' => $reportId,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            // 失敗情報を反映
            $report->severity = 'medium';
            $report->category = 'other';
            $report->analysis = [
                'status' => 'failed',
                'ai_error' => [
                    'message' => $e->getMessage(),
                    'exception_class' => $e::class,
                    'occurred_at' => now()->toIso8601String(),
                ],
            ];

            // DB保存モードの場合はDBに保存
            if ($storageDriver === 'database') {
                $report->update([
                    'severity' => $report->severity,
                    'category' => $report->category,
                    'analysis' => $report->analysis,
                ]);
            }
        }
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
            'sqlite' => $errorCode === '19',
            'sqlsrv' => $errorCode === '2627',
            default => $errorCode === '23000',
        };
    }
}
