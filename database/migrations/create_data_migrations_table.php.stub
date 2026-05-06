<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('data-migrations.connection'))
            ->create(config('data-migrations.table', 'data_migrations'), function (Blueprint $table) {
                $table->id();
                $table->string('migration_name');
                $table->string('scope_type');
                $table->string('target_key')->default('');
                $table->string('connection_name');
                $table->unsignedInteger('batch');
                $table->string('status');
                $table->string('checksum')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();

                $table->unique(['migration_name', 'scope_type', 'target_key'], 'data_migrations_unique');
            });
    }

    public function down(): void
    {
        Schema::connection(config('data-migrations.connection'))
            ->dropIfExists(config('data-migrations.table', 'data_migrations'));
    }
};
