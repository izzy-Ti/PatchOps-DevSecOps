<?php

namespace App\Console\Commands;

use App\Vulnerability\VulnerabilityIngestionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncGithubAlertsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'github:sync-alerts {--repo= : Target repository owner/repo (defaults to config)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch and ingest real open security alerts from GitHub Dependabot for the configured repository';

    /**
     * Execute the console command.
     */
    public function handle(VulnerabilityIngestionService $ingestionService): int
    {
        $token = config('services.github.token');
        $repository = $this->option('repo') ?: config('services.github.repository', 'izzy-Ti/PatchOps-DevSecOps');

        if (empty($token)) {
            $this->error('GitHub token is not configured.');
            $this->line('Please add your GitHub token to your .env file:');
            $this->line('  GITHUB_TOKEN=your_personal_access_token_here');
            $this->line('  GITHUB_REPOSITORY=izzy-Ti/PatchOps-DevSecOps');

            return Command::FAILURE;
        }

        $this->info("Connecting to GitHub API for repository: [{$repository}]...");

        $apiUrl = "https://api.github.com/repos/{$repository}/dependabot/alerts";

        try {
            $response = Http::withToken($token)
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ])
                ->timeout(15)
                ->get($apiUrl, [
                    'state' => 'open',
                    'per_page' => 30,
                ]);

            if ($response->unauthorized()) {
                $this->error('GitHub API Authentication failed (401 Unauthorized). Please verify your GITHUB_TOKEN has "security_events" or "repo" scope.');

                return Command::FAILURE;
            }

            if ($response->forbidden()) {
                $this->error('Access to Dependabot alerts forbidden (403). Ensure Dependabot alerts are enabled on this repository and your token has permission.');

                return Command::FAILURE;
            }

            if (! $response->successful()) {
                $this->error("GitHub API error [{$response->status()}]: {$response->body()}");

                return Command::FAILURE;
            }

            $alerts = $response->json();

            if (empty($alerts)) {
                $this->info("No open Dependabot alerts found for [{$repository}]. Repository is clean!");

                return Command::SUCCESS;
            }

            $count = count($alerts);
            $this->info("Found {$count} open security alerts. Ingesting into PatchOps...");

            $ingested = 0;
            foreach ($alerts as $alert) {
                $payload = [
                    'alert' => $alert,
                    'repository' => [
                        'full_name' => $repository,
                    ],
                ];

                $incident = $ingestionService->ingest($payload, 'github');
                $this->line("  ✓ Ingested: [{$incident->incident_number}] {$incident->title}");
                $ingested++;
            }

            $this->info("Successfully ingested {$ingested} real incidents into PatchOps!");

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Exception connecting to GitHub: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }
}
