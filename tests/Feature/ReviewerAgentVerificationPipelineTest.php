<?php

use App\Agents\ReviewerAgent;
use App\DTOs\ReviewerContextDTO;
use App\DTOs\ReviewResultDTO;
use App\Exceptions\MCP\UnauthorizedToolException;
use App\Models\Incident;
use App\Services\MCP\Guards\ToolPermissionGuard;
use App\Services\MCP\MCPToolGateway;
use App\Services\Verification\DiffAuditorService;
use App\Services\Verification\PoCMitigationVerifier;
use App\Services\Verification\RegressionTestRunner;
use App\Tools\Enums\AgentRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.anthropic.key', null);
});

test('DiffAuditorService passes for clean security diff', function () {
    $auditor = new DiffAuditorService;
    $cleanDiff = <<<'DIFF'
--- a/src/AuthHandler.php
+++ b/src/AuthHandler.php
@@ -20,6 +20,7 @@
     public function verifyToken(string $token): bool
     {
+        $token = trim(htmlspecialchars($token, ENT_QUOTES, 'UTF-8'));
         return strlen($token) > 0;
     }
DIFF;

    $result = $auditor->audit($cleanDiff);

    expect($result->passed)->toBeTrue()
        ->and($result->violations)->toBeEmpty();
});

test('DiffAuditorService rejects forbidden infrastructure files', function () {
    $auditor = new DiffAuditorService;
    $badFileDiff = <<<'DIFF'
--- a/.github/workflows/deploy.yml
+++ b/.github/workflows/deploy.yml
@@ -5,6 +5,7 @@
     steps:
+      - run: echo "bypassing CI check"
DIFF;

    $result = $auditor->audit($badFileDiff);

    expect($result->passed)->toBeFalse()
        ->and($result->violations[0])->toContain('Modified forbidden infrastructure file');
});

test('DiffAuditorService rejects leaked credentials, debug code, and permissive configs', function () {
    $auditor = new DiffAuditorService;

    $debugDiff = <<<'DIFF'
--- a/src/Service.php
+++ b/src/Service.php
@@ -10,2 +10,4 @@
+ dd($userSession);
+ console.log("debug token");
DIFF;

    $resDebug = $auditor->audit($debugDiff);
    expect($resDebug->passed)->toBeFalse()
        ->and(count($resDebug->violations))->toBeGreaterThanOrEqual(2);

    $secretDiff = <<<'DIFF'
--- a/src/Config.php
+++ b/src/Config.php
@@ -5,2 +5,3 @@
+ $apiKey = 'ghp_ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
DIFF;

    $resSecret = $auditor->audit($secretDiff);
    expect($resSecret->passed)->toBeFalse()
        ->and($resSecret->violations[0])->toContain('Potential credential or API key leaked');

    $permissiveDiff = <<<'DIFF'
--- a/src/Security.php
+++ b/src/Security.php
@@ -10,2 +10,3 @@
+ system("chmod 777 /var/data");
DIFF;

    $resPermissive = $auditor->audit($permissiveDiff);
    expect($resPermissive->passed)->toBeFalse()
        ->and($resPermissive->violations[0])->toContain('Insecure permissive file permissions');
});

test('ToolPermissionGuard enforces strict read-only boundary for AgentRole::REVIEWER', function () {
    // Read-only tools must be allowed
    ToolPermissionGuard::assertPermission(AgentRole::REVIEWER, 'workspace.read_file');
    ToolPermissionGuard::assertPermission(AgentRole::REVIEWER, 'workspace.list_files');
    ToolPermissionGuard::assertPermission(AgentRole::REVIEWER, 'sandbox.create');
    ToolPermissionGuard::assertPermission(AgentRole::REVIEWER, 'sandbox.execute');
    ToolPermissionGuard::assertPermission(AgentRole::REVIEWER, 'record_review_verdict');

    // Mutation tools must be strictly denied
    expect(fn () => ToolPermissionGuard::assertPermission(AgentRole::REVIEWER, 'workspace.write_file'))
        ->toThrow(UnauthorizedToolException::class);

    expect(fn () => ToolPermissionGuard::assertPermission(AgentRole::REVIEWER, 'repository.modify'))
        ->toThrow(UnauthorizedToolException::class);

    expect(fn () => ToolPermissionGuard::assertPermission(AgentRole::REVIEWER, 'github.create_pull_request'))
        ->toThrow(UnauthorizedToolException::class);
});

test('PoCMitigationVerifier detects exploit still active vs cleanly mitigated', function () {
    $incident = Incident::factory()->create();

    // Case 1: Exploit still succeeds
    $mockGatewayFail = Mockery::mock(MCPToolGateway::class);
    $mockGatewayFail->shouldReceive('execute')
        ->once()
        ->andReturn([
            'exit_code' => 0,
            'stdout' => 'EXPLOIT SUCCESSFUL: root:x:0:0 forged session',
            'stderr' => '',
        ]);

    $verifierFail = new PoCMitigationVerifier($mockGatewayFail);
    $resFail = $verifierFail->verifyMitigation($incident, 'sb-101', 'python exploit.py');

    expect($resFail['mitigated'])->toBeFalse();

    // Case 2: Exploit blocked
    $mockGatewayPass = Mockery::mock(MCPToolGateway::class);
    $mockGatewayPass->shouldReceive('execute')
        ->once()
        ->andReturn([
            'exit_code' => 1,
            'stdout' => 'HTTP 400 Bad Request: Invalid payload signature',
            'stderr' => '',
        ]);

    $verifierPass = new PoCMitigationVerifier($mockGatewayPass);
    $resPass = $verifierPass->verifyMitigation($incident, 'sb-101', 'python exploit.py');

    expect($resPass['mitigated'])->toBeTrue();
});

test('RegressionTestRunner runs generated test and broader test suites', function () {
    $incident = Incident::factory()->create();

    $mockGateway = Mockery::mock(MCPToolGateway::class);
    $mockGateway->shouldReceive('execute')
        ->twice()
        ->andReturn([
            'exit_code' => 0,
            'stdout' => 'Tests passed (12 passed, 24 assertions)',
            'stderr' => '',
        ]);

    $runner = new RegressionTestRunner($mockGateway);
    $result = $runner->runTests(
        incident: $incident,
        sandboxId: 'sb-202',
        regressionCommand: 'npm run test:cve',
        testSuites: ['npm test']
    );

    expect($result['passed'])->toBeTrue()
        ->and($result['checks'])->toHaveCount(2)
        ->and($result['failed_tests'])->toBeEmpty();
});

test('ReviewerAgent rejects candidate at Stage 1 when diff contains forbidden patterns', function () {
    $context = ReviewerContextDTO::fromArray([
        'incident_id' => 'INC-TEST-001',
        'cve_identifier' => 'CVE-2026-9000',
        'repository' => 'acme/core',
        'base_commit_sha' => 'abcdef1',
        'patch_candidate_diff' => "--- a/Dockerfile\n+++ b/Dockerfile\n+ RUN chmod 777 /app\n",
    ]);

    $agent = app(ReviewerAgent::class);
    $result = $agent->execute($context);

    expect($result)->toBeInstanceOf(ReviewResultDTO::class)
        ->and($result->approved)->toBeFalse()
        ->and($result->verdictSummary)->toBe('Patch candidate failed deterministic safety audit.')
        ->and($result->metadata['stage'])->toBe('stage_1_diff_audit');
});

test('ReviewerAgent rejects candidate at Stage 2 when vulnerability remains exploitable', function () {
    $mockPocVerifier = Mockery::mock(PoCMitigationVerifier::class);
    $mockPocVerifier->shouldReceive('verifyMitigation')
        ->once()
        ->andReturn([
            'mitigated' => false,
            'exit_code' => 0,
            'stdout' => 'EXPLOIT SUCCESSFUL: root:x:0:0',
            'stderr' => '',
            'evidence_changed' => true,
        ]);

    $context = ReviewerContextDTO::fromArray([
        'incident_id' => 'INC-TEST-002',
        'cve_identifier' => 'CVE-2026-9001',
        'repository' => 'acme/core',
        'base_commit_sha' => 'abcdef2',
        'patch_candidate_diff' => "--- a/src/Handler.php\n+++ b/src/Handler.php\n+ // attempt fix\n",
        'reproduction_evidence' => ['command' => 'python exploit.py'],
        'sandbox_id' => 'sb-test-303',
    ]);

    $agent = new ReviewerAgent(
        pocVerifier: $mockPocVerifier
    );

    $result = $agent->execute($context);

    expect($result->approved)->toBeFalse()
        ->and($result->vulnerabilityMitigated)->toBeFalse()
        ->and($result->verdictSummary)->toContain('Original vulnerability is still exploitable after patch.');
});

test('ReviewerAgent rejects candidate at Stage 3 when regression tests fail', function () {
    $mockPocVerifier = Mockery::mock(PoCMitigationVerifier::class);
    $mockPocVerifier->shouldReceive('verifyMitigation')
        ->once()
        ->andReturn([
            'mitigated' => true,
            'exit_code' => 1,
            'stdout' => '400 Bad Request',
            'stderr' => '',
            'evidence_changed' => true,
        ]);

    $mockTestRunner = Mockery::mock(RegressionTestRunner::class);
    $mockTestRunner->shouldReceive('runTests')
        ->once()
        ->andReturn([
            'passed' => false,
            'checks' => [
                [
                    'check_name' => 'Regression Test',
                    'command' => 'npm test',
                    'exit_code' => 1,
                    'passed' => false,
                    'details' => 'TokenTest failed with NullPointerException',
                ],
            ],
            'failed_tests' => ['TokenTest failed with NullPointerException'],
            'stdout' => '',
            'stderr' => 'TokenTest failed with NullPointerException',
        ]);

    $context = ReviewerContextDTO::fromArray([
        'incident_id' => 'INC-TEST-003',
        'cve_identifier' => 'CVE-2026-9002',
        'repository' => 'acme/core',
        'base_commit_sha' => 'abcdef3',
        'patch_candidate_diff' => "--- a/src/Token.php\n+++ b/src/Token.php\n+ return null;\n",
        'reproduction_evidence' => ['command' => 'python exploit.py'],
        'regression_test_command' => 'npm test',
        'sandbox_id' => 'sb-test-404',
    ]);

    $agent = new ReviewerAgent(
        pocVerifier: $mockPocVerifier,
        testRunner: $mockTestRunner
    );

    $result = $agent->execute($context);

    expect($result->approved)->toBeFalse()
        ->and($result->vulnerabilityMitigated)->toBeTrue()
        ->and($result->regressionsDetected)->toBeTrue()
        ->and($result->verdictSummary)->toContain('Generated regression test failed to pass');
});

test('ReviewerAgent approves candidate when all stages pass cleanly', function () {
    $mockPocVerifier = Mockery::mock(PoCMitigationVerifier::class);
    $mockPocVerifier->shouldReceive('verifyMitigation')
        ->once()
        ->andReturn([
            'mitigated' => true,
            'exit_code' => 1,
            'stdout' => 'Blocked payload',
            'stderr' => '',
            'evidence_changed' => true,
        ]);

    $mockTestRunner = Mockery::mock(RegressionTestRunner::class);
    $mockTestRunner->shouldReceive('runTests')
        ->once()
        ->andReturn([
            'passed' => true,
            'checks' => [
                [
                    'check_name' => 'Regression Test',
                    'command' => 'npm test',
                    'exit_code' => 0,
                    'passed' => true,
                    'details' => 'All 15 tests passed',
                ],
            ],
            'failed_tests' => [],
            'stdout' => 'All 15 tests passed',
            'stderr' => '',
        ]);

    $context = ReviewerContextDTO::fromArray([
        'incident_id' => 'INC-TEST-004',
        'cve_identifier' => 'CVE-2026-9003',
        'repository' => 'acme/core',
        'base_commit_sha' => 'abcdef4',
        'patch_candidate_diff' => "--- a/src/Sanitizer.php\n+++ b/src/Sanitizer.php\n+ return htmlspecialchars(\$val);\n",
        'reproduction_evidence' => ['command' => 'python exploit.py'],
        'regression_test_command' => 'npm test',
        'sandbox_id' => 'sb-test-505',
    ]);

    $agent = new ReviewerAgent(
        pocVerifier: $mockPocVerifier,
        testRunner: $mockTestRunner
    );

    $result = $agent->execute($context);

    expect($result->approved)->toBeTrue()
        ->and($result->vulnerabilityMitigated)->toBeTrue()
        ->and($result->regressionsDetected)->toBeFalse()
        ->and($result->checks)->toHaveCount(2);
});
