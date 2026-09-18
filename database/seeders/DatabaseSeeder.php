<?php

namespace Database\Seeders;

use App\Enums\IncidentStatus;
use App\Enums\VulnerabilitySeverity;
use App\Enums\VulnerabilitySource;
use App\Models\AuditEvent;
use App\Models\Incident;
use App\Models\PatchArtifact;
use App\Models\QualityGateCheck;
use App\Models\QualityGateRun;
use App\Models\User;
use App\Models\Vulnerability;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with realistic production DevSecOps incidents.
     */
    public function run(): void
    {
        // 1. Authenticated Operator User
        User::firstOrCreate(
            ['email' => 'secops@patchops.dev'],
            [
                'name' => 'SecOps Lead Operator',
                'password' => bcrypt('password'),
            ]
        );

        // 2. Incident 1: Gated at HITL (Awaiting Approval)
        $vuln1 = Vulnerability::firstOrCreate(
            ['cve_id' => 'CVE-2024-3400'],
            [
                'source' => VulnerabilitySource::SNYK,
                'source_id' => 'SNYK-PHP-AUTH-68912',
                'title' => 'Arbitrary Command Injection in GlobalProtect Gateway Handler',
                'description' => 'Unauthenticated command injection flaw in session validation parameter allowing full root perimeter compromise.',
                'severity' => VulnerabilitySeverity::CRITICAL,
                'package_name' => 'vendor/auth-core',
                'affected_version' => '< 2.4.1',
                'fixed_version' => '>= 2.4.1',
                'repository' => 'izzy-Ti/PatchOps-DevSecOps',
                'reference_url' => 'https://nvd.nist.gov/vuln/detail/CVE-2024-3400',
            ]
        );

        $incAwaiting = Incident::updateOrCreate(
            ['incident_number' => 'INC-4920'],
            [
                'correlation_id' => 'corr-4920-hitl-gate',
                'vulnerability_id' => $vuln1->id,
                'repository' => 'izzy-Ti/PatchOps-DevSecOps',
                'severity' => VulnerabilitySeverity::CRITICAL,
                'title' => 'Arbitrary Command Injection in GlobalProtect Gateway Handler',
                'description' => 'Unauthenticated command injection flaw in session validation parameter allowing full root perimeter compromise.',
                'status' => IncidentStatus::AWAITING_APPROVAL,
                'patch_iterations' => 1,
                'root_cause' => 'User-supplied session token parameter is concatenated directly into system shell string without sanitization or escaping in SessionAuthService.php.',
                'metadata' => [
                    'cve_identifier' => 'CVE-2024-3400',
                    'affected_packages' => ['auth-core' => '^2.4.0'],
                    'environment' => 'production-us-east',
                    'reproduction_command' => 'php artisan security:test-exploit --vector=session_rce',
                    'reproduction_output' => "EXPLOIT PAYLOAD TRIGGERED: uid=0(root) gid=0(root) groups=0(root)\nArbitrary command executed successfully.",
                    'mitigation_output' => "HTTP/1.1 403 Forbidden\nError: Malformed session token format. Injection vector blocked.",
                ],
            ]
        );

        $patch = PatchArtifact::updateOrCreate(
            ['incident_id' => $incAwaiting->id],
            [
                'diff' => <<<'DIFF'
--- a/app/Services/Auth/SessionAuthService.php
+++ b/app/Services/Auth/SessionAuthService.php
@@ -42,7 +42,9 @@ class SessionAuthService
     public function validateSession(string $rawToken): bool
     {
-        $cmd = "verify-session-token " . $rawToken;
-        return exec($cmd) === 0;
+        if (!preg_match('/^[a-zA-Z0-9_-]{32,64}$/', $rawToken)) {
+            throw new InvalidSessionTokenException('Invalid token format.');
+        }
+        return $this->cryptoValidator->verify($rawToken);
     }
 }
DIFF,
                'status' => 'verified',
            ]
        );

        $qgRun = QualityGateRun::updateOrCreate(
            ['incident_id' => $incAwaiting->id, 'iteration' => 1],
            [
                'patch_id' => $patch->id,
                'passed' => true,
                'total_checks' => 8,
                'passed_checks' => 8,
                'failed_checks' => 0,
            ]
        );

        $checks = [
            ['check_type' => 'patch_integrity', 'cmd' => 'git apply --check unified_patch.diff', 'ms' => 42, 'out' => 'Patch applied cleanly to target branch without hunk fuzz.'],
            ['check_type' => 'isolated_build', 'cmd' => 'composer install --no-dev && npm run build', 'ms' => 4120, 'out' => 'Build succeeded. All production assets compiled.'],
            ['check_type' => 'unit_tests', 'cmd' => 'php artisan test --compact', 'ms' => 2850, 'out' => 'All 58 test suites passed (100% green).'],
            ['check_type' => 'affected_tests', 'cmd' => 'pest tests/Feature/SessionAuthServiceTest.php', 'ms' => 620, 'out' => '8 feature tests passed with zero regressions.'],
            ['check_type' => 'exploit_reproduction', 'cmd' => 'php artisan test:exploit --cve=CVE-2024-3400', 'ms' => 910, 'out' => 'Exploit payload blocked: HTTP 403 Forbidden verified.'],
            ['check_type' => 'static_analysis', 'cmd' => 'vendor/bin/phpstan analyse --level=8', 'ms' => 3100, 'out' => 'No static analysis errors found.'],
            ['check_type' => 'dependency_audit', 'cmd' => 'composer audit --locked', 'ms' => 1240, 'out' => 'Zero known vulnerabilities in locked dependencies.'],
            ['check_type' => 'security_policy', 'cmd' => 'policy-guard verify --zero-trust', 'ms' => 180, 'out' => 'Cryptographic signature and identity invariants validated.'],
        ];

        foreach ($checks as $c) {
            QualityGateCheck::firstOrCreate(
                [
                    'quality_gate_run_id' => $qgRun->id,
                    'check_type' => $c['check_type'],
                ],
                [
                    'status' => 'PASSED',
                    'exit_code' => 0,
                    'duration_ms' => $c['ms'],
                    'stdout' => $c['out'],
                ]
            );
        }

        if (AuditEvent::where('incident_id', $incAwaiting->id)->count() === 0) {
            AuditEvent::insert([
                [
                    'id' => 'audit_ingest_'.uniqid(),
                    'incident_id' => $incAwaiting->id,
                    'actor_type' => 'SERVICE',
                    'actor_id' => 'WebhookIngestion',
                    'action' => 'incident.ingested',
                    'resource_type' => 'Incident',
                    'resource_id' => (string) $incAwaiting->id,
                    'metadata' => json_encode(['source' => 'Snyk Webhook', 'severity' => 'critical']),
                    'ip_address' => '127.0.0.1',
                    'created_at' => now()->subMinutes(25),
                ],
                [
                    'id' => 'audit_triage_'.uniqid(),
                    'incident_id' => $incAwaiting->id,
                    'actor_type' => 'AGENT',
                    'actor_id' => 'TriageAgent',
                    'action' => 'triage.completed',
                    'resource_type' => 'Incident',
                    'resource_id' => (string) $incAwaiting->id,
                    'metadata' => json_encode(['confidence' => 0.98, 'reproducible' => true]),
                    'ip_address' => '127.0.0.1',
                    'created_at' => now()->subMinutes(18),
                ],
                [
                    'id' => 'audit_qg_'.uniqid(),
                    'incident_id' => $incAwaiting->id,
                    'actor_type' => 'GATE',
                    'actor_id' => 'QualityGateEngine',
                    'action' => 'quality_gate.passed',
                    'resource_type' => 'QualityGateRun',
                    'resource_id' => (string) $qgRun->id,
                    'metadata' => json_encode(['total_checks' => 8, 'passed' => 8, 'duration_ms' => 13062]),
                    'ip_address' => '127.0.0.1',
                    'created_at' => now()->subMinutes(4),
                ],
            ]);
        }

        // 3. Incident 2: In-Progress Validation (Iteration 2/3)
        $vuln2 = Vulnerability::firstOrCreate(
            ['cve_id' => 'CVE-2024-21413'],
            [
                'source' => VulnerabilitySource::GITHUB,
                'source_id' => 'GHSA-2024-21413',
                'title' => 'Arbitrary File Overwrite via Path Traversal in Multipart Upload',
                'description' => 'Relative path traversal via unsanitized filename header allowing attacker to overwrite files outside target storage root.',
                'severity' => VulnerabilitySeverity::HIGH,
                'package_name' => 'vendor/storage-s3',
                'affected_version' => '< 1.2.1',
                'fixed_version' => '>= 1.2.1',
                'repository' => 'izzy-Ti/StorageGateway',
            ]
        );

        Incident::updateOrCreate(
            ['incident_number' => 'INC-3814'],
            [
                'correlation_id' => 'corr-3814-validating',
                'vulnerability_id' => $vuln2->id,
                'repository' => 'izzy-Ti/StorageGateway',
                'severity' => VulnerabilitySeverity::HIGH,
                'title' => 'Arbitrary File Overwrite via Path Traversal in Multipart Upload',
                'description' => 'Relative path traversal via unsanitized filename header allowing attacker to overwrite files outside target storage root.',
                'status' => IncidentStatus::VALIDATING,
                'patch_iterations' => 2,
                'root_cause' => 'Filename parameter lacks basename normalization before disk write.',
                'metadata' => [
                    'cve_identifier' => 'CVE-2024-21413',
                    'affected_packages' => ['storage-s3' => '^1.2.0'],
                ],
            ]
        );

        // 4. Incident 3: Remediated
        $vuln3 = Vulnerability::firstOrCreate(
            ['cve_id' => 'CVE-2023-4863'],
            [
                'source' => VulnerabilitySource::CVE,
                'source_id' => 'CVE-2023-4863',
                'title' => 'Heap Buffer Overflow in WebP Decoder Library',
                'description' => 'Out-of-bounds write vulnerability leading to arbitrary code execution in graphics worker.',
                'severity' => VulnerabilitySeverity::CRITICAL,
                'package_name' => 'libwebp',
                'affected_version' => '< 1.3.2',
                'fixed_version' => '>= 1.3.2',
                'repository' => 'izzy-Ti/AnalyticsEngine',
            ]
        );

        Incident::updateOrCreate(
            ['incident_number' => 'INC-2041'],
            [
                'correlation_id' => 'corr-2041-remediated',
                'vulnerability_id' => $vuln3->id,
                'repository' => 'izzy-Ti/AnalyticsEngine',
                'severity' => VulnerabilitySeverity::CRITICAL,
                'title' => 'Heap Buffer Overflow in WebP Decoder Library',
                'description' => 'Out-of-bounds write vulnerability leading to arbitrary code execution in graphics worker.',
                'status' => IncidentStatus::REMEDIATED,
                'patch_iterations' => 1,
                'root_cause' => 'Outdated libwebp dependency in worker Docker container base.',
                'metadata' => [
                    'cve_identifier' => 'CVE-2023-4863',
                    'pull_request_url' => 'https://github.com/izzy-Ti/AnalyticsEngine/pull/42',
                ],
            ]
        );

        // 5. Incident 4: Reproducing
        $vuln4 = Vulnerability::firstOrCreate(
            ['cve_id' => 'CVE-2023-38606'],
            [
                'source' => VulnerabilitySource::SNYK,
                'source_id' => 'SNYK-PHP-GUZZLE-9102',
                'title' => 'Server-Side Request Forgery (SSRF) in Callback Dispatcher',
                'description' => 'Webhook URL validator allows redirects to AWS metadata endpoint 169.254.169.254.',
                'severity' => VulnerabilitySeverity::MEDIUM,
                'package_name' => 'guzzlehttp/guzzle',
                'affected_version' => '< 7.4.5',
                'fixed_version' => '>= 7.4.5',
                'repository' => 'izzy-Ti/WebhookWorker',
            ]
        );

        Incident::updateOrCreate(
            ['incident_number' => 'INC-1980'],
            [
                'correlation_id' => 'corr-1980-reproducing',
                'vulnerability_id' => $vuln4->id,
                'repository' => 'izzy-Ti/WebhookWorker',
                'severity' => VulnerabilitySeverity::MEDIUM,
                'title' => 'Server-Side Request Forgery (SSRF) in Callback Dispatcher',
                'description' => 'Webhook URL validator allows redirects to AWS metadata endpoint 169.254.169.254.',
                'status' => IncidentStatus::REPRODUCING,
                'patch_iterations' => 1,
                'root_cause' => 'Missing HTTP redirect validation after initial DNS check.',
                'metadata' => [
                    'cve_identifier' => 'CVE-2023-38606',
                    'affected_packages' => ['guzzlehttp/guzzle' => '^7.4.0'],
                ],
            ]
        );
    }
}
