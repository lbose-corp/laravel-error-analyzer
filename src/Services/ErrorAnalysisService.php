<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Services;

use Illuminate\Support\Facades\Cache;

/**
 * AIエラー分析のクォータ管理サービス
 */
final class ErrorAnalysisService
{
    /**
     * 分析可能か判定する
     */
    public function canAnalyze(): bool
    {
        return $this->getRemainingQuota() > 0;
    }

    /**
     * 原子的にクォータチェックとインクリメントを実行する
     * クォータが残っている場合のみインクリメントしてtrueを返す
     */
    public function tryIncrementIfAllowed(): bool
    {
        $key = $this->cacheKey();
        $limit = (int) config('error-analyzer.analysis.daily_limit', 100);
        $expiresAt = now()->endOfDay();

        return Cache::lock($key.':lock', 5)->get(function () use ($key, $limit, $expiresAt): bool {
            $count = (int) Cache::get($key, 0);
            if ($count >= $limit) {
                return false;
            }

            Cache::put($key, $count + 1, $expiresAt);

            return true;
        });
    }

    /**
     * 分析回数をインクリメントする（原子的に）
     */
    public function incrementCount(): void
    {
        $key = $this->cacheKey();
        $expiresAt = now()->endOfDay();

        Cache::add($key, 0, $expiresAt); // TTLつきで初期化（既にあれば何もしない）
        Cache::increment($key);
    }

    /**
     * 残りクォータを取得する
     */
    public function getRemainingQuota(): int
    {
        $limit = (int) config('error-analyzer.analysis.daily_limit', 100);
        $count = $this->getTodayCount();

        return max(0, $limit - $count);
    }

    /**
     * 本日の分析回数を取得する
     */
    public function getTodayCount(): int
    {
        return (int) Cache::get($this->cacheKey(), 0);
    }

    /**
     * 本日のカウントをリセットする
     */
    public function resetDailyCount(): void
    {
        Cache::forget($this->cacheKey());
    }

    /**
     * 日別のキャッシュキーを生成する
     */
    private function cacheKey(): string
    {
        return sprintf('error_analysis_count:%s', now()->format('Ymd'));
    }
}
