<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Commands;

use Illuminate\Console\Command;
use Lbose\ErrorAnalyzer\Models\ErrorReport;

/**
 * 古いエラーレポートを削除するコマンド
 */
class CleanupOldErrors extends Command
{
    protected $signature = 'errors:cleanup
                            {--days= : 削除する日数（デフォルトは設定ファイルの値）}
                            {--dry-run : 実際には削除せず、削除対象のみを表示}';

    protected $description = '古いエラーレポートを削除します。';

    public function handle(): int
    {
        $storageDriver = (string) config('error-analyzer.storage.driver', 'database');

        if ($storageDriver !== 'database') {
            $this->info('DB保存が無効になっているため、削除対象のエラーレポートはありません。');

            return self::SUCCESS;
        }

        $daysOption = $this->option('days');
        $days = $daysOption !== null
            ? (int) $daysOption
            : (int) config('error-analyzer.storage.cleanup_days', 90);

        if ($days < 1) {
            $this->error('--days は 1 以上を指定してください。');

            return self::FAILURE;
        }

        $threshold = now()->subDays($days);

        $query = ErrorReport::where('occurred_at', '<', $threshold);
        $count = $query->count();

        if ($count === 0) {
            $this->info('削除対象のエラーレポートはありません。');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info(sprintf('%d件のエラーレポートが削除対象です。（%d日以前）', $count, $days));

            return self::SUCCESS;
        }

        $deleted = $query->delete();

        $this->info(sprintf('%d件のエラーレポートを削除しました。（%d日以前）', $deleted, $days));

        return self::SUCCESS;
    }
}
