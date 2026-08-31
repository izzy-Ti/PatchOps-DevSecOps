<?php

namespace App\Console\Commands;

use App\Models\Sandbox;
use App\Services\MCP\MCPToolGateway;
use App\Tools\Enums\AgentRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReapExpiredSandboxesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sandboxes:reap';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Purge disposable sandboxes that have exceeded their hard expiration limit.';

    /**
     * Execute the console command.
     */
    public function handle(MCPToolGateway $gateway): int
    {
        $expiredSandboxes = Sandbox::query()
            ->whereNotIn('status', ['destroyed', 'expired'])
            ->where('expires_at', '<=', now())
            ->with('incident')
            ->get();

        $reapedCount = 0;

        foreach ($expiredSandboxes as $sandbox) {
            try {
                if ($sandbox->incident) {
                    $gateway->invoke(
                        role: AgentRole::REPRODUCTION,
                        toolName: 'sandbox.destroy_environment',
                        arguments: ['workspace_id' => $sandbox->sandbox_id],
                        context: $sandbox->incident,
                    );
                }

                $sandbox->update([
                    'status' => 'expired',
                    'destroyed_at' => now(),
                ]);

                $reapedCount++;
                Log::warning("Reaped expired sandbox [{$sandbox->sandbox_id}] for incident [{$sandbox->incident_id}].");
            } catch (Throwable $e) {
                Log::error("Failed to reap sandbox [{$sandbox->sandbox_id}]: {$e->getMessage()}");
            }
        }

        $this->info("Reaped {$reapedCount} expired sandboxes.");

        return self::SUCCESS;
    }
}
