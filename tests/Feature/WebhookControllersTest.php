<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('github webhook responds with pong on ping event', function () {
    $response = $this->postJson('/api/v1/webhooks/github', ['zen' => 'Keep it logically awesome.'], [
        'X-GitHub-Event' => 'ping',
    ]);

    $response->assertOk()
        ->assertJson(['message' => 'pong']);
});

test('github webhook ingests dependabot alert and returns standard json envelope', function () {
    $payload = [
        'alert' => [
            'number' => 77,
            'html_url' => 'https://github.com/izzy-Ti/patchops-api/security/dependabot/77',
            'security_advisory' => [
                'ghsa_id' => 'GHSA-7777-8888-9999',
                'cve_id' => 'CVE-2026-7777',
                'summary' => 'Remote Code Execution in guzzlehttp/guzzle',
                'severity' => 'critical',
            ],
            'dependency' => [
                'package' => [
                    'name' => 'guzzlehttp/guzzle',
                ],
            ],
        ],
        'repository' => [
            'full_name' => 'izzy-Ti/patchops-api',
        ],
    ];

    $response = $this->postJson('/api/v1/webhooks/github', $payload);

    $response->assertCreated()
        ->assertJson([
            'success' => true,
            'message' => 'Vulnerability ingested successfully',
            'data' => [
                'source' => 'github',
                'severity' => 'critical',
                'status' => 'open',
            ],
        ])
        ->assertJsonStructure([
            'data' => [
                'incident_id',
                'incident_number',
                'correlation_id',
            ],
        ]);

    $this->assertDatabaseHas('vulnerabilities', [
        'source' => 'github',
        'source_id' => 'GHSA-7777-8888-9999',
        'package_name' => 'guzzlehttp/guzzle',
    ]);

    $this->assertDatabaseHas('incidents', [
        'severity' => 'critical',
        'status' => 'open',
    ]);
});

test('snyk webhook ingests vulnerability alert and returns standard json envelope', function () {
    $payload = [
        'issue' => [
            'id' => 'SNYK-PHP-LARAVEL-5555',
            'cve' => 'CVE-2026-5555',
            'title' => 'Cross-Site Scripting in Blade Engine',
            'severity' => 'high',
            'package' => 'laravel/framework',
            'version' => '< 11.5.0',
            'fixVersion' => '11.5.0',
            'url' => 'https://security.snyk.io/vuln/SNYK-PHP-LARAVEL-5555',
        ],
        'project' => [
            'name' => 'izzy-Ti/patchops-web',
        ],
    ];

    $response = $this->postJson('/api/v1/webhooks/snyk', $payload);

    $response->assertCreated()
        ->assertJson([
            'success' => true,
            'message' => 'Vulnerability ingested successfully',
            'data' => [
                'source' => 'snyk',
                'severity' => 'high',
                'status' => 'open',
            ],
        ])
        ->assertJsonStructure([
            'data' => [
                'incident_id',
                'incident_number',
                'correlation_id',
            ],
        ]);

    $this->assertDatabaseHas('vulnerabilities', [
        'source' => 'snyk',
        'source_id' => 'SNYK-PHP-LARAVEL-5555',
        'package_name' => 'laravel/framework',
    ]);
});

test('cve webhook ingests generic / trivy alert and returns standard json envelope', function () {
    $payload = [
        'VulnerabilityID' => 'CVE-2026-3333',
        'PkgName' => 'openssl',
        'InstalledVersion' => '1.1.1',
        'FixedVersion' => '1.1.1k',
        'Severity' => 'CRITICAL',
        'Title' => 'Buffer Overflow in OpenSSL',
        'Description' => 'Remote memory corruption flaw.',
        'Target' => 'alpine:3.18',
        'PrimaryURL' => 'https://nvd.nist.gov/vuln/detail/CVE-2026-3333',
    ];

    $response = $this->postJson('/api/v1/webhooks/cve', $payload);

    $response->assertCreated()
        ->assertJson([
            'success' => true,
            'message' => 'Vulnerability ingested successfully',
            'data' => [
                'source' => 'cve',
                'severity' => 'critical',
                'status' => 'open',
            ],
        ])
        ->assertJsonStructure([
            'data' => [
                'incident_id',
                'incident_number',
                'correlation_id',
            ],
        ]);

    $this->assertDatabaseHas('vulnerabilities', [
        'source' => 'cve',
        'source_id' => 'CVE-2026-3333',
        'package_name' => 'openssl',
    ]);
});
