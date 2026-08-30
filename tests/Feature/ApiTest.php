<?php

use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('health check returns status and services status', function () {
    $response = $this->getJson('/api/health');

    $response->assertOk()
        ->assertJsonStructure([
            'status',
            'services' => [
                'api',
                'database',
                'redis',
            ],
        ])
        ->assertHeader('X-Correlation-ID');
});

test('user can register via api and receive sanctum token', function () {
    $payload = [
        'name' => 'Alice Dev',
        'email' => 'alice@example.com',
        'password' => 'secret1234',
        'password_confirmation' => 'secret1234',
    ];

    $response = $this->postJson('/api/auth/register', $payload);

    $response->assertCreated()
        ->assertJson([
            'success' => true,
            'data' => [
                'user' => [
                    'name' => 'Alice Dev',
                    'email' => 'alice@example.com',
                    'role' => 'viewer',
                ],
            ],
        ])
        ->assertJsonStructure([
            'data' => ['token'],
        ]);

    $this->assertDatabaseHas('users', [
        'email' => 'alice@example.com',
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'event' => 'user.registered',
    ]);
});

test('user registration fails when validation rules fail', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => '',
        'email' => 'not-an-email',
        'password' => 'short',
        'password_confirmation' => 'mismatch',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'email', 'password']);
});

test('user can login with valid credentials', function () {
    $user = User::factory()->create([
        'email' => 'bob@example.com',
        'password' => bcrypt('password123'),
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email' => 'bob@example.com',
        'password' => 'password123',
    ]);

    $response->assertOk()
        ->assertJson([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'email' => 'bob@example.com',
                ],
            ],
        ])
        ->assertJsonStructure([
            'data' => ['token'],
        ]);
});

test('user login fails with invalid credentials', function () {
    User::factory()->create([
        'email' => 'bob@example.com',
        'password' => bcrypt('password123'),
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email' => 'bob@example.com',
        'password' => 'wrongpassword',
    ]);

    $response->assertStatus(401)
        ->assertJson([
            'success' => false,
            'message' => 'Invalid credentials.',
        ]);
});

test('authenticated user can logout and revoke token', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/auth/logout');

    $response->assertOk()
        ->assertJson([
            'success' => true,
            'data' => [
                'message' => 'Successfully logged out.',
            ],
        ]);
});

test('unauthenticated users cannot access incidents endpoints', function () {
    $this->getJson('/api/incidents')->assertUnauthorized();
    $this->getJson('/api/incidents/1')->assertUnauthorized();
    $this->postJson('/api/incidents', [])->assertUnauthorized();
});

test('authenticated user can create an incident and get correlation_id', function () {
    $user = User::factory()->developer()->create();
    Sanctum::actingAs($user);

    $payload = [
        'title' => 'SQL Injection Vulnerability in User Search',
        'description' => 'Unsanitized input found in search parameter of SearchController.',
        'severity' => 'high',
        'metadata' => [
            'cve' => 'CVE-2024-9999',
            'component' => 'SearchModule',
        ],
    ];

    $response = $this->postJson('/api/incidents', $payload, [
        'X-Correlation-ID' => 'INC-VULN-001',
    ]);

    $response->assertCreated()
        ->assertJson([
            'success' => true,
            'correlation_id' => 'INC-VULN-001',
            'data' => [
                'title' => 'SQL Injection Vulnerability in User Search',
                'severity' => 'high',
                'status' => 'open',
                'correlation_id' => 'INC-VULN-001',
            ],
        ]);

    $this->assertDatabaseHas('incidents', [
        'title' => 'SQL Injection Vulnerability in User Search',
        'severity' => 'high',
        'correlation_id' => 'INC-VULN-001',
    ]);

    expect(AuditLog::where('event', 'incident.created')->where('correlation_id', 'INC-VULN-001')->exists())
        ->toBeTrue();
});

test('authenticated user can list and filter incidents', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    Incident::factory()->create(['severity' => 'critical', 'status' => 'open']);
    Incident::factory()->create(['severity' => 'low', 'status' => 'resolved']);
    Incident::factory()->create(['severity' => 'critical', 'status' => 'triaging']);

    $response = $this->getJson('/api/incidents');
    $response->assertOk()
        ->assertJsonStructure([
            'success',
            'data',
            'meta' => ['current_page', 'last_page', 'total'],
        ]);

    expect($response->json('meta.total'))->toBe(3);

    // Filter by severity
    $filterResponse = $this->getJson('/api/incidents?severity=critical');
    $filterResponse->assertOk();
    expect($filterResponse->json('meta.total'))->toBe(2);

    // Filter by status
    $statusResponse = $this->getJson('/api/incidents?status=resolved');
    $statusResponse->assertOk();
    expect($statusResponse->json('meta.total'))->toBe(1);
});

test('authenticated user can view single incident', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $incident = Incident::factory()->create(['title' => 'Buffer Overflow in Auth Service']);

    $response = $this->getJson("/api/incidents/{$incident->id}");

    $response->assertOk()
        ->assertJson([
            'success' => true,
            'data' => [
                'id' => $incident->id,
                'title' => 'Buffer Overflow in Auth Service',
            ],
        ]);
});
