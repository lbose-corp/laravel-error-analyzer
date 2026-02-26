# Laravel Error Analyzer（日本語版）

Laravel アプリケーション向けの AI 駆動エラー分析、GitHub Issue 作成、Slack 通知パッケージです。

## 機能

- 🤖 **AI 分析**: Google Gemini AI を使ってエラー内容を分析
- 🐛 **Issue 自動作成**: 重大なエラーを GitHub Issue として自動起票
- 📢 **Slack 通知**: Slack チャンネルへ通知を送信
- 🔒 **PII 保護**: スタックトレースやコンテキストの機微情報を自動マスキング
- 🚦 **クォータ管理**: API の過剰利用を防ぐ日次上限
- 🔄 **重複排除**: 同じエラーの多重分析を抑制
- 🎯 **柔軟な設定**: 環境変数で機能の有効/無効を簡単に切り替え

## 動作要件

- PHP 8.3 以上
- Laravel 11.0 以上

## インストール

Composer でパッケージをインストールします。

```bash
composer require lbose/laravel-error-analyzer
```

設定ファイルを公開します。

```bash
php artisan vendor:publish --tag=error-analyzer-config
```

マイグレーションを実行します（`ERROR_ANALYZER_STORAGE_DRIVER=database` の場合、パッケージのマイグレーションは自動ロードされます）。

```bash
php artisan migrate
```

## 設定

### 環境変数

`.env` に以下を追加してください。

```env
# AI Analyzer（任意）
ERROR_ANALYZER_DRIVER=gemini
ERROR_ANALYZER_GEMINI_API_KEY=your-gemini-api-key

# Issue Tracker（任意）
ERROR_ANALYZER_ISSUE_TRACKER=github
ERROR_ANALYZER_GITHUB_TOKEN=your-github-token
ERROR_ANALYZER_GITHUB_REPOSITORY=username/repository
ERROR_ANALYZER_GITHUB_AI_TITLE_ENABLED=false
ERROR_ANALYZER_GITHUB_AI_TITLE_MODEL=gemini-2.5-flash-lite

# Notifications（任意）
ERROR_ANALYZER_NOTIFICATION=slack
ERROR_ANALYZER_SLACK_WEBHOOK=https://hooks.slack.com/services/xxx

# Analysis Settings
ERROR_ANALYZER_DAILY_LIMIT=100
ERROR_ANALYZER_ENABLED_ENVIRONMENTS=production,staging

# Storage Settings（任意）
ERROR_ANALYZER_STORAGE_DRIVER=database  # 'database'（既定） or 'null'（DB保存無効）
```

### オプション依存パッケージ

利用する機能に応じて追加インストールします。

```bash
# Gemini AI 分析
composer require google-gemini-php/laravel
```

`ERROR_ANALYZER_GITHUB_AI_TITLE_ENABLED=true` を設定すると、IssueタイトルをGeminiで任意生成できます。生成に失敗した場合は既存のルールベースタイトルへ自動フォールバックし、タイトル生成の呼び出し回数は AI分析と同じ `ERROR_ANALYZER_DAILY_LIMIT` クォータに含まれます。

## 基本的な使い方

### 例外ハンドラーへの組み込み

例外ハンドラーでエラー分析ジョブを起動します。

```php
use Lbose\ErrorAnalyzer\Jobs\AnalyzeErrorJob;
use Lbose\ErrorAnalyzer\Services\ErrorAnalysisService;

class Handler extends ExceptionHandler
{
    public function report(Throwable $exception): void
    {
        parent::report($exception);

        if ($this->shouldAnalyze($exception)) {
            $service = app(ErrorAnalysisService::class);

            if ($service->tryIncrementIfAllowed()) {
                dispatch(new AnalyzeErrorJob($exception, [
                    'environment' => app()->environment(),
                    'timestamp' => now()->toIso8601String(),
                    'url' => request()->fullUrl(),
                    'user_id' => auth()->check() ? (string) auth()->id() : 'guest',
                ]));
            }
        }
    }

    private function shouldAnalyze(Throwable $exception): bool
    {
        $enabledEnvironments = config('error-analyzer.analysis.enabled_environments', []);
        if (!in_array(app()->environment(), $enabledEnvironments, true)) {
            return false;
        }

        $excluded = config('error-analyzer.analysis.excluded_exceptions', []);
        foreach ($excluded as $excludedException) {
            if ($exception instanceof $excludedException) {
                return false;
            }
        }

        return true;
    }
}
```

### Artisan コマンド

エラー分析のテスト:

```bash
# テスト用エラーを発生
php artisan errors:test-analysis --trigger=runtime

# エラーレポート一覧
php artisan errors:test-analysis --list

# 特定のエラーレポート表示
php artisan errors:test-analysis --show=1
```

古いエラーのクリーンアップ:

```bash
php artisan errors:cleanup
```

## 高度な設定

### カスタム AI アナライザー

独自の AI アナライザーを実装できます。

```php
use Lbose\ErrorAnalyzer\Services\Contracts\AiAnalyzerInterface;

class CustomAnalyzer implements AiAnalyzerInterface
{
    public function analyze(
        string $exceptionClass,
        string $message,
        string $file,
        int $line,
        string $sanitizedTrace,
        array $sanitizedContext
    ): array {
        // 独自実装
        return [
            'severity' => 'high',
            'category' => 'runtime',
            'root_cause' => 'Custom analysis',
            'impact' => 'Custom impact',
            'solution' => 'Custom solution',
            'related_code' => '',
        ];
    }
}
```

サービスプロバイダで登録します。

```php
$this->app->singleton(AiAnalyzerInterface::class, CustomAnalyzer::class);
```

### ストレージ設定

デフォルトではエラーレポートはデータベースに保存されます。以下で DB 保存を無効化できます。

```env
ERROR_ANALYZER_STORAGE_DRIVER=null
```

DB 保存を無効化した場合:

- エラーレポートは DB に保存されません
- 重複排除は DB のユニーク制約ではなくキャッシュを利用します
- AI 分析、通知、Issue 作成は通常どおり動作します
- `errors:test-analysis --list` や `errors:cleanup` は利用できません（DB 保存が必要）

### 設定項目一覧

利用可能な設定は `config/error-analyzer.php` を参照してください。

- AI analyzer 設定（driver, model, temperature など）
- Issue tracker 設定（repository, labels, assignees）
- 通知設定（webhook, severity threshold）
- 分析動作設定（日次上限, 除外例外, 重複排除）
- ストレージ設定（driver, table name, cleanup days）

## アーキテクチャ

本パッケージは driver ベースの構成で、主に以下のコンポーネントで構成されます。

- **AiAnalyzerInterface**: AI ベースのエラー分析（Gemini / custom / null）
- **IssueTrackerInterface**: Issue 自動作成（GitHub / null）
- **NotificationChannelInterface**: エラー通知（Slack / null）
- **ErrorAnalysisService**: クォータ管理・レート制限
- **FingerprintCalculator**: エラー重複判定ロジック
- **PiiSanitizer**: スタックトレース・コンテキストの機微情報除去

## テスト

パッケージにはテストが含まれています。

```bash
cd packages/laravel-error-analyzer
composer install
vendor/bin/phpunit
```

## ライセンス

MIT License. 詳細は [LICENSE](LICENSE) を参照してください。

## クレジット

[LBOSE Corp](https://lbose.co.jp) により開発
