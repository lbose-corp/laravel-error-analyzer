<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * エラーレポートモデル
 *
 * @property int $id
 * @property string $exception_class
 * @property string $message
 * @property string $file
 * @property int $line
 * @property string $fingerprint
 * @property int $dedupe_window
 * @property string $trace
 * @property string $severity
 * @property string $category
 * @property array<string, mixed> $analysis
 * @property array<string, mixed>|null $context
 * @property \Illuminate\Support\Carbon $occurred_at
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class ErrorReport extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'exception_class',
        'message',
        'file',
        'line',
        'fingerprint',
        'dedupe_window',
        'trace',
        'severity',
        'category',
        'analysis',
        'context',
        'occurred_at',
        'resolved_at',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return (string) config('error-analyzer.storage.table_name', 'error_reports') ?: 'error_reports';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'analysis' => 'array',
            'context' => 'array',
            'occurred_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * 未解決のエラーのみ取得するスコープ
     *
     * @param  Builder<ErrorReport>  $query
     * @return Builder<ErrorReport>
     */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    /**
     * Criticalのエラーのみ取得するスコープ
     *
     * @param  Builder<ErrorReport>  $query
     * @return Builder<ErrorReport>
     */
    public function scopeCritical(Builder $query): Builder
    {
        return $query->where('severity', 'critical');
    }

    /**
     * 指定日数内のエラーのみ取得するスコープ
     *
     * @param  Builder<ErrorReport>  $query
     * @return Builder<ErrorReport>
     */
    public function scopeRecent(Builder $query, int $days = 7): Builder
    {
        return $query->where('occurred_at', '>', now()->subDays($days));
    }

    /**
     * 根本原因のアクセサ
     */
    public function getRootCauseAttribute(): ?string
    {
        return $this->analysis['root_cause'] ?? null;
    }

    /**
     * 推奨修正のアクセサ
     */
    public function getRecommendedFixAttribute(): ?string
    {
        return $this->analysis['recommended_fix'] ?? null;
    }
}
