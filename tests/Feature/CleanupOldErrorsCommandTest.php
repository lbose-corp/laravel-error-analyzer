<?php

declare(strict_types=1);

namespace Lbose\ErrorAnalyzer\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lbose\ErrorAnalyzer\Models\ErrorReport;
use Lbose\ErrorAnalyzer\Tests\TestCase;

class CleanupOldErrorsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_old_errors(): void
    {
        config(['error-analyzer.storage.cleanup_days' => 90]);

        // Create an error older than 90 days
        ErrorReport::create([
            'exception_class' => 'RuntimeException',
            'message' => 'Old error',
            'file' => '/path/to/file.php',
            'line' => 42,
            'fingerprint' => 'old-fingerprint',
            'dedupe_window' => 12345,
            'trace' => 'Stack trace...',
            'severity' => 'medium',
            'category' => 'other',
            'analysis' => [],
            'context' => [],
            'occurred_at' => now()->subDays(100),
        ]);

        // Create a recent error
        ErrorReport::create([
            'exception_class' => 'RuntimeException',
            'message' => 'Recent error',
            'file' => '/path/to/file.php',
            'line' => 43,
            'fingerprint' => 'recent-fingerprint',
            'dedupe_window' => 12346,
            'trace' => 'Stack trace...',
            'severity' => 'medium',
            'category' => 'other',
            'analysis' => [],
            'context' => [],
            'occurred_at' => now()->subDays(10),
        ]);

        $this->assertDatabaseCount('error_reports', 2);

        $this->artisan('errors:cleanup')
            ->assertExitCode(0);

        $this->assertDatabaseCount('error_reports', 1);
        $this->assertDatabaseMissing('error_reports', ['message' => 'Old error']);
        $this->assertDatabaseHas('error_reports', ['message' => 'Recent error']);
    }

    public function test_respects_custom_days_parameter(): void
    {
        config(['error-analyzer.storage.cleanup_days' => 90]);

        // Create errors at different ages
        ErrorReport::create([
            'exception_class' => 'RuntimeException',
            'message' => 'Error 60 days old',
            'file' => '/path/to/file.php',
            'line' => 42,
            'fingerprint' => 'fingerprint-60',
            'dedupe_window' => 12345,
            'trace' => 'Stack trace...',
            'severity' => 'medium',
            'category' => 'other',
            'analysis' => [],
            'context' => [],
            'occurred_at' => now()->subDays(60),
        ]);

        ErrorReport::create([
            'exception_class' => 'RuntimeException',
            'message' => 'Error 20 days old',
            'file' => '/path/to/file.php',
            'line' => 43,
            'fingerprint' => 'fingerprint-20',
            'dedupe_window' => 12346,
            'trace' => 'Stack trace...',
            'severity' => 'medium',
            'category' => 'other',
            'analysis' => [],
            'context' => [],
            'occurred_at' => now()->subDays(20),
        ]);

        $this->assertDatabaseCount('error_reports', 2);

        // Cleanup with custom days=30 should only delete the 60-day-old error
        $this->artisan('errors:cleanup', ['--days' => 30])
            ->assertExitCode(0);

        $this->assertDatabaseCount('error_reports', 1);
        $this->assertDatabaseMissing('error_reports', ['message' => 'Error 60 days old']);
        $this->assertDatabaseHas('error_reports', ['message' => 'Error 20 days old']);
    }

    public function test_dry_run_does_not_delete(): void
    {
        config(['error-analyzer.storage.cleanup_days' => 90]);

        ErrorReport::create([
            'exception_class' => 'RuntimeException',
            'message' => 'Old error',
            'file' => '/path/to/file.php',
            'line' => 42,
            'fingerprint' => 'old-fingerprint',
            'dedupe_window' => 12345,
            'trace' => 'Stack trace...',
            'severity' => 'medium',
            'category' => 'other',
            'analysis' => [],
            'context' => [],
            'occurred_at' => now()->subDays(100),
        ]);

        $this->assertDatabaseCount('error_reports', 1);

        $this->artisan('errors:cleanup', ['--dry-run' => true])
            ->assertExitCode(0);

        // Should not delete in dry-run mode
        $this->assertDatabaseCount('error_reports', 1);
    }

    public function test_handles_no_old_errors(): void
    {
        config(['error-analyzer.storage.cleanup_days' => 90]);

        ErrorReport::create([
            'exception_class' => 'RuntimeException',
            'message' => 'Recent error',
            'file' => '/path/to/file.php',
            'line' => 42,
            'fingerprint' => 'recent-fingerprint',
            'dedupe_window' => 12345,
            'trace' => 'Stack trace...',
            'severity' => 'medium',
            'category' => 'other',
            'analysis' => [],
            'context' => [],
            'occurred_at' => now()->subDays(10),
        ]);

        $this->artisan('errors:cleanup')
            ->expectsOutput('削除対象のエラーレポートはありません。')
            ->assertExitCode(0);

        $this->assertDatabaseCount('error_reports', 1);
    }

    public function test_handles_null_storage_driver(): void
    {
        // DB保存を無効化
        config()->set('error-analyzer.storage.driver', 'null');

        $this->artisan('errors:cleanup')
            ->expectsOutput('DB保存が無効になっているため、削除対象のエラーレポートはありません。')
            ->assertExitCode(0);
    }
}
