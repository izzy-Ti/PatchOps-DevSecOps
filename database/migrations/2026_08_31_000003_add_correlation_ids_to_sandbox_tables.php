<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('sandboxes')) {
            Schema::table('sandboxes', function (Blueprint $table) {
                if (! Schema::hasColumn('sandboxes', 'agent_run_id')) {
                    $table->string('agent_run_id', 64)->nullable()->after('status');
                }
                if (! Schema::hasColumn('sandboxes', 'correlation_id')) {
                    $table->string('correlation_id', 64)->nullable()->after('agent_run_id');
                }
                $table->index('agent_run_id', 'idx_sandboxes_agent_run');
            });
        }

        if (! Schema::hasTable('sandbox_executions')) {
            Schema::create('sandbox_executions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
                $table->string('sandbox_id', 64);
                $table->string('agent_run_id', 64)->nullable();
                $table->string('correlation_id', 64);
                $table->string('command');
                $table->integer('exit_code')->default(0);
                $table->longText('stdout')->nullable();
                $table->longText('stderr')->nullable();
                $table->decimal('duration_ms', 10, 2)->default(0.00);
                $table->timestamps();

                $table->index(['incident_id', 'agent_run_id', 'sandbox_id', 'correlation_id'], 'idx_sandbox_exec_trace');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sandbox_executions');

        if (Schema::hasTable('sandboxes')) {
            Schema::table('sandboxes', function (Blueprint $table) {
                $table->dropIndex('idx_sandboxes_agent_run');
                $table->dropColumn(['agent_run_id', 'correlation_id']);
            });
        }
    }
};
