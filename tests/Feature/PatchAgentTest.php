<?php

use App\Agents\PatchAgent;
use App\DTOs\AgentResultDTO;
use App\Enums\IncidentStatus;
use App\Jobs\GeneratePatchJob;
use App\Jobs\ValidatePatchJob;
use App\Models\Incident;
use App\Models\Vulnerability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.anthropic.key', null);
});

test('PatchAgent synthesizes patch and regression tests via Claude tool calling', function () {
    config()->set('services.anthropic.key', 'sk-ant-test-key');

    $mockDiff = <<<'DIFF'
--- a/src/SecurityService.php
+++ b/src/SecurityService.php
@@ -5,6 +5,7 @@
     public function verify(string $token): bool
     {
+        if (empty($token)) return false;
         return hash_equals($this->secret, $token);
     }
DIFF;

    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::response([
            'id' => 'msg_patch_01',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_patch_01',
                    'name' => 'record_patch_synthesis',
                    'input' => [
                        'root_cause' => 'Timing attack vulnerability when comparing auth tokens without constant time comparison.',
                        'fix_summary' => 'Replaced string comparison with hash_equals and added regression tests.',
                        'diff' => $mockDiff,
                        'changed_files' => ['src/SecurityService.php'],
                        'tests_added' => ['tests/SecurityServiceTest.php'],
                    ],
                ],
            ],
        ], 200),
    ]);

    $vuln = Vulnerability::factory()->create([
        'package_name' => 'vendor/auth-core',
        'cve_id' => 'CVE-2026-3333',
    ]);

    $incident = Incident::factory()->create([
        'vulnerability_id' => $vuln->id,
        'status' => IncidentStatus::REPRODUCED,
    ]);

    $agent = new PatchAgent;
    $result = $agent->generatePatch($incident);

    expect($result)->toBeInstanceOf(AgentResultDTO::class)
        ->and($result->success)->toBeTrue()
        ->and($result->status)->toBe('completed')
        ->and($result->data['root_cause'])->toContain('Timing attack')
        ->and($result->data['diff'])->toContain('hash_equals')
        ->and($result->data['changed_files'])->toBe(['src/SecurityService.php'])
        ->and($result->data['tests_added'])->toBe(['tests/SecurityServiceTest.php']);
});

test('GeneratePatchJob executes patch synthesis, transitions to VALIDATING, and dispatches ValidatePatchJob', function () {
    Queue::fake();
    config()->set('services.anthropic.key', 'sk-ant-test-key');

    $mockDiff = <<<'DIFF'
--- a/src/Parser.php
+++ b/src/Parser.php
@@ -10,2 +10,4 @@
+        // Input sanitization
+        $input = strip_tags($input);
DIFF;

    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::response([
            'content' => [
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_patch_02',
                    'name' => 'record_patch_synthesis',
                    'input' => [
                        'root_cause' => 'XSS via raw HTML parsing in user profile.',
                        'fix_summary' => 'Applied strip_tags before rendering output.',
                        'diff' => $mockDiff,
                        'changed_files' => ['src/Parser.php'],
                        'tests_added' => ['tests/ParserTest.php'],
                    ],
                ],
            ],
        ], 200),
    ]);

    $incident = Incident::factory()->create([
        'status' => IncidentStatus::REPRODUCED,
    ]);

    $job = new GeneratePatchJob($incident);
    app()->call([$job, 'handle']);

    $incident->refresh();

    expect($incident->status)->toBe(IncidentStatus::VALIDATING)
        ->and($incident->root_cause)->toBe('XSS via raw HTML parsing in user profile.')
        ->and($incident->metadata['fix_summary'])->toBe('Applied strip_tags before rendering output.')
        ->and($incident->metadata['diff'])->toContain('strip_tags')
        ->and($incident->metadata['changed_files'])->toBe(['src/Parser.php']);

    Queue::assertPushed(ValidatePatchJob::class, function ($job) use ($incident) {
        return $job->incident->id === $incident->id;
    });
});

test('GeneratePatchJob escalates incident when patch synthesis fails', function () {
    Queue::fake();
    config()->set('services.anthropic.key', 'sk-ant-test-key');

    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::response(['error' => 'Invalid Schema'], 400),
    ]);

    $incident = Incident::factory()->create([
        'status' => IncidentStatus::REPRODUCED,
    ]);

    $job = new GeneratePatchJob($incident);
    app()->call([$job, 'handle']);

    $incident->refresh();

    expect($incident->status)->toBe(IncidentStatus::ESCALATED)
        ->and($incident->metadata['error_history'])->toHaveCount(1);

    Queue::assertNotPushed(ValidatePatchJob::class);
});
