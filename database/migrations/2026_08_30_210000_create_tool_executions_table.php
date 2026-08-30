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
        Schema::create('tool_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->foreignId('agent_run_id')->nullable()->constrained('agent_runs')->nullOnDelete();
            $table->string('tool_name');
            $table->json('arguments')->nullable();
            $table->json('result')->nullable();
            $table->string('status')->default('running');
            $table->string('permission')->nullable();
            $table->string('risk_level')->nullable();
            $table->string('correlation_id')->nullable()->index();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->decimal('duration_ms', 10, 2)->nullable();
            $table->timestamps();

            $table->index(['incident_id', 'tool_name']);
            $table->index(['incident_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tool_executions');
    }
};
