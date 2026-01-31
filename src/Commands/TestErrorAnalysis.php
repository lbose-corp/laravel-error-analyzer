<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Lbose\ErrorAnalyzer\Jobs\AnalyzeErrorJob;
use Lbose\ErrorAnalyzer\Models\ErrorReport;
use Lbose\ErrorAnalyzer\Services\ErrorAnalysisService;
use RuntimeException;

/**
 * エラー分析フローの動作確認用コマンド
 */
class TestErrorAnalysis extends Command
{
    protected $signature = 'errors:test-analysis
                            {--type=runtime : エラーの種類 (runtime, query, unexpected)}
                            {--sync : ジョブを同期的に実行する}
                            {--list : エラーレポートの一覧を表示する}
                            {--show= : 指定したIDのエラーレポートを表示する}';

    protected $description = 'エラー分析フローの動作確認を行います。';

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->listErrorReports();
        }

        if ($this->option('show')) {
            return $this->showErrorReport((int) $this->option('show'));
        }

        return $this->testErrorAnalysis();
    }

    /**
     * DB保存が有効かどうかを確認する
     */
    private function isDatabaseStorageEnabled(): bool
    {
        return config('error-analyzer.storage.driver', 'database') === 'database';
    }

    /**
     * エラー分析をテストする
     */
    private function testErrorAnalysis(): int
    {
        $type = (string) $this->option('type');
        $sync = (bool) $this->option('sync');

        $this->info('エラー分析のテストを開始します...');
        $this->newLine();

        // 環境設定の確認
        $enabledEnvironments = config('error-analyzer.analysis.enabled_environments', ['production']);
        $currentEnv = app()->environment();
        $this->info(sprintf('現在の環境: %s', $currentEnv));
        $this->info(sprintf('有効な環境: %s', implode(', ', $enabledEnvironments)));

        if (! in_array($currentEnv, $enabledEnvironments, true)) {
            $this->warn('現在の環境は有効な環境リストに含まれていません。');
            $this->warn('エラー分析は実行されません。');
            $this->newLine();
            $this->info('有効にするには、以下の設定を確認してください:');
            $this->line('  ERROR_ANALYZER_ENABLED_ENVIRONMENTS='.implode(',', array_merge($enabledEnvironments, [$currentEnv])));
            $this->newLine();

            if (! $this->confirm('それでも続行しますか？', false)) {
                return self::FAILURE;
            }
        }

        // クォータの確認
        $service = app(ErrorAnalysisService::class);
        $remainingQuota = $service->getRemainingQuota();
        $dailyLimit = (int) config('error-analyzer.analysis.daily_limit', 100);
        $this->info(sprintf('残りクォータ: %d / %d', $remainingQuota, $dailyLimit));

        if ($remainingQuota <= 0) {
            $this->error('本日の分析クォータが上限に達しています。');

            return self::FAILURE;
        }

        $this->newLine();

        // 例外を作成
        $exception = $this->createException($type);
        $this->info(sprintf('例外タイプ: %s', $exception::class));
        $this->info(sprintf('メッセージ: %s', $exception->getMessage()));

        $this->newLine();

        if ($sync) {
            $this->info('ジョブを同期的に実行します...');
            $this->runJobSync($exception);
        } else {
            $this->info('ジョブをキューに投入します...');
            $this->runJobAsync($exception);
        }

        return self::SUCCESS;
    }

    /**
     * 例外を作成する
     */
    private function createException(string $type): \Throwable
    {
        return match ($type) {
            'runtime' => new RuntimeException('テスト用のRuntimeExceptionです。エラー分析の動作確認のために意図的に発生させました。'),
            'query' => new QueryException(
                'mysql',
                'SELECT * FROM non_existent_table',
                [],
                new \PDOException('Table \'laravel.non_existent_table\' doesn\'t exist'),
            ),
            'unexpected' => new \UnexpectedValueException('テスト用のUnexpectedValueExceptionです。予期しない値が検出されました。'),
            default => throw new \InvalidArgumentException(sprintf('不明なエラータイプ: %s', $type)),
        };
    }

    /**
     * ジョブを同期的に実行する
     */
    private function runJobSync(\Throwable $exception): void
    {
        $service = app(ErrorAnalysisService::class);
        if (! $service->tryIncrementIfAllowed()) {
            $this->error('クォータチェックに失敗しました。');

            return;
        }

        $job = new AnalyzeErrorJob($exception, [
            'environment' => app()->environment(),
            'timestamp' => now()->toIso8601String(),
            'url' => 'cli://test-error-analysis',
            'user_id' => 'cli',
            'ip' => '127.0.0.1',
            'user_agent' => 'CLI Test Command',
        ]);

        try {
            $job->handle(
                app(\Lbose\ErrorAnalyzer\Services\Contracts\AiAnalyzerInterface::class),
                app(\Lbose\ErrorAnalyzer\Services\Contracts\IssueTrackerInterface::class),
                app(\Lbose\ErrorAnalyzer\Services\Contracts\NotificationChannelInterface::class),
                app(\Lbose\ErrorAnalyzer\Helpers\FingerprintCalculator::class),
                app(\Lbose\ErrorAnalyzer\Helpers\PiiSanitizer::class),
            );
            $this->info('✓ ジョブの実行が完了しました。');

            // 最新のエラーレポートを取得（DB保存が有効な場合のみ）
            if ($this->isDatabaseStorageEnabled()) {
                $report = ErrorReport::latest('id')->first();
                if ($report) {
                    $this->newLine();
                    $this->displayErrorReport($report);
                }
            } else {
                $this->info('DB保存が無効のため、エラーレポートは表示されません。');
            }
        } catch (\Throwable $e) {
            $this->error('ジョブの実行中にエラーが発生しました:');
            $this->error($e->getMessage());
            $this->error($e->getTraceAsString());
        }
    }

    /**
     * ジョブを非同期で実行する
     */
    private function runJobAsync(\Throwable $exception): void
    {
        $service = app(ErrorAnalysisService::class);
        if (! $service->tryIncrementIfAllowed()) {
            $this->error('クォータチェックに失敗しました。');

            return;
        }

        dispatch(new AnalyzeErrorJob($exception, [
            'environment' => app()->environment(),
            'timestamp' => now()->toIso8601String(),
            'url' => 'cli://test-error-analysis',
            'user_id' => 'cli',
            'ip' => '127.0.0.1',
            'user_agent' => 'CLI Test Command',
        ]));

        $this->info('✓ ジョブをキューに投入しました。');
        $this->newLine();
        $this->info('キューを処理するには、以下のコマンドを実行してください:');
        $this->line('  php artisan queue:work');
        $this->newLine();
        $this->info('エラーレポートを確認するには、以下のコマンドを実行してください:');
        $this->line('  php artisan errors:test-analysis --list');
    }

    /**
     * エラーレポートの一覧を表示する
     */
    private function listErrorReports(): int
    {
        if (! $this->isDatabaseStorageEnabled()) {
            $this->error('DB保存が無効になっています。--list オプションはDB保存が有効な場合のみ使用できます。');
            $this->info('DB保存を有効にするには、設定ファイルで error-analyzer.storage.driver を "database" に設定してください。');

            return self::FAILURE;
        }

        $reports = ErrorReport::orderBy('occurred_at', 'desc')
            ->limit(20)
            ->get();

        if ($reports->isEmpty()) {
            $this->info('エラーレポートが見つかりませんでした。');

            return self::SUCCESS;
        }

        $this->info(sprintf('最新の%d件のエラーレポート:', $reports->count()));
        $this->newLine();

        $headers = ['ID', '例外クラス', '重要度', 'カテゴリ', '発生日時', 'ステータス'];
        $rows = [];

        foreach ($reports as $report) {
            $status = $report->analysis['status'] ?? 'unknown';
            $rows[] = [
                $report->id,
                class_basename($report->exception_class),
                $report->severity,
                $report->category,
                $report->occurred_at->format('Y-m-d H:i:s'),
                $status,
            ];
        }

        $this->table($headers, $rows);

        $this->newLine();
        $this->info('詳細を確認するには、以下のコマンドを実行してください:');
        $this->line('  php artisan errors:test-analysis --show=<ID>');

        return self::SUCCESS;
    }

    /**
     * エラーレポートの詳細を表示する
     */
    private function showErrorReport(int $id): int
    {
        $report = ErrorReport::find($id);

        if (! $report) {
            $this->error(sprintf('ID %d のエラーレポートが見つかりませんでした。', $id));

            return self::FAILURE;
        }

        $this->displayErrorReport($report);

        return self::SUCCESS;
    }

    /**
     * エラーレポートの詳細を表示する
     */
    private function displayErrorReport(ErrorReport $report): void
    {
        $this->info('=== エラーレポート詳細 ===');
        $this->newLine();

        $this->line(sprintf('ID: %d', $report->id));
        $this->line(sprintf('例外クラス: %s', $report->exception_class));
        $this->line(sprintf('メッセージ: %s', $report->message));
        $this->line(sprintf('ファイル: %s:%d', $report->file, $report->line));
        $this->line(sprintf('重要度: %s', $report->severity));
        $this->line(sprintf('カテゴリ: %s', $report->category));
        $this->line(sprintf('発生日時: %s', $report->occurred_at->format('Y-m-d H:i:s')));
        $this->line(sprintf('フィンガープリント: %s', $report->fingerprint));
        $this->newLine();

        $analysis = $report->analysis;
        if (isset($analysis['status'])) {
            $this->line(sprintf('分析ステータス: %s', $analysis['status']));
        }

        if (isset($analysis['root_cause'])) {
            $this->newLine();
            $this->info('【根本原因】');
            $this->line($analysis['root_cause']);
        }

        if (isset($analysis['impact'])) {
            $this->newLine();
            $this->info('【影響】');
            $this->line($analysis['impact']);
        }

        if (isset($analysis['immediate_action'])) {
            $this->newLine();
            $this->info('【即時対応】');
            $this->line($analysis['immediate_action']);
        }

        if (isset($analysis['recommended_fix'])) {
            $this->newLine();
            $this->info('【推奨修正】');
            $this->line($analysis['recommended_fix']);
        }

        if (isset($analysis['prevention'])) {
            $this->newLine();
            $this->info('【再発防止】');
            $this->line($analysis['prevention']);
        }

        if (isset($analysis['github_issue'])) {
            $this->newLine();
            $this->info('【GitHub Issue】');
            $githubIssue = $analysis['github_issue'];
            if (isset($githubIssue['url'])) {
                $this->line(sprintf('URL: %s', $githubIssue['url']));
            }
            if (isset($githubIssue['status'])) {
                $this->line(sprintf('ステータス: %s', $githubIssue['status']));
            }
        }

        if ($report->context) {
            $this->newLine();
            $this->info('【コンテキスト】');
            $this->line(json_encode($report->context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
    }
}
