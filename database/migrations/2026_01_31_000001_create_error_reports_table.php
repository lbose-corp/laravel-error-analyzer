<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('error-analyzer.storage.table_name', 'error_reports');

        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table): void {
            $table->id();
            $table->string('exception_class');
            $table->text('message');
            $table->string('file');
            $table->integer('line');
            $table->string('fingerprint', 64);
            $table->integer('dedupe_window');
            $table->text('trace');
            $table->string('severity', 20);
            $table->string('category', 50);
            $table->json('analysis');
            $table->json('context')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // ユニーク制約により重複排除
            $table->unique(['fingerprint', 'dedupe_window']);

            // インデックス
            $table->index('occurred_at');
            $table->index('severity');
            $table->index('category');
            $table->index(['severity', 'occurred_at']);
        });
    }

    public function down(): void
    {
        $tableName = config('error-analyzer.storage.table_name', 'error_reports');
        Schema::dropIfExists($tableName);
    }
};
