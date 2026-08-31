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
        Schema::create('sandboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->string('sandbox_id', 64)->unique();
            $table->string('runtime', 32)->default('node');
            $table->string('runtime_version', 16)->nullable();
            $table->string('repository', 255)->nullable();
            $table->string('commit_sha', 40)->nullable();
            $table->string('status', 32)->default('initialized');
            $table->timestamp('expires_at')->useCurrent();
            $table->timestamp('destroyed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at'], 'idx_sandboxes_expiration');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sandboxes');
    }
};
