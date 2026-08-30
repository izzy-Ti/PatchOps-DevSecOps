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
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->string('agent_type');
            $table->string('status')->default('running');
            $table->unsignedInteger('attempt')->default(1);
            $table->json('input_context')->nullable();
            $table->json('output')->nullable();
            $table->json('error')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->decimal('duration', 8, 3)->nullable();
            $table->string('correlation_id')->nullable()->index();
            $table->timestamps();

            $table->index(['incident_id', 'agent_type']);
            $table->index(['incident_id', 'attempt']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};
