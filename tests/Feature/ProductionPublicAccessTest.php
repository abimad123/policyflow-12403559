<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionPublicAccessTest extends TestCase
{
    use RefreshDatabase;

    private string $originalEnvironment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->originalEnvironment = app()->environment();
        app()->detectEnvironment(fn () => 'production');
        config()->set('app.debug', false);
        config()->set('policyflow.public_demo_enabled', false);
    }

    protected function tearDown(): void
    {
        app()->detectEnvironment(fn () => $this->originalEnvironment);
        parent::tearDown();
    }

    private function policy(string $clientId, string $agentId, string $name): PolicyRequest
    {
        return PolicyRequest::create([
            'client_request_id' => $clientId,
            'agent_id' => $agentId,
            'customer_name' => $name,
            'date_of_birth' => '1990-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
            'premium_amount' => '60.00',
            'status' => 'VALIDATED',
        ]);
    }

    public function test_homepage_is_public_but_records_and_writes_are_closed_when_demo_is_off(): void
    {
        $this->get('/')->assertOk()->assertSee('Public browsing is available')->assertSee('New policy request');
        $this->getJson('/api/policy-requests')->assertOk()
            ->assertJsonPath('pagination.total', 0)
            ->assertJsonPath('demo_enabled', false);
        $this->get('/policies/new')->assertOk()->assertSee('Demo submission is currently disabled');
        $this->get('/policies/1')->assertNotFound();
        $this->getJson('/api/policy-requests/1')->assertNotFound();
        $this->postJson('/api/policy-requests', [])->assertForbidden();
        $this->postJson('/api/mock-insurer/callback', [])->assertForbidden();
    }

    public function test_enabled_demo_public_reads_only_synthetic_records(): void
    {
        config()->set('policyflow.public_demo_enabled', true);
        $synthetic = $this->policy('DEMO-12345678-1234-1234-1234-123456789abc', 'DEMO-AGENT', 'Demo Traveler');
        $private = $this->policy('PRIVATE-1', 'PRIVATE-AGENT', 'Private Person');

        $this->get('/')->assertOk()->assertSee('Public synthetic demo')->assertDontSee('demo-write-token');
        $this->getJson('/api/policy-requests')->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.id', $synthetic->id)
            ->assertDontSee('Private Person');
        $this->get('/policies/new')->assertOk();
        $this->get("/policies/{$synthetic->id}")->assertOk();
        $this->getJson("/api/policy-requests/{$synthetic->id}")->assertOk();
        $this->getJson("/api/policy-requests/{$private->id}")->assertNotFound();
    }

    public function test_public_synthetic_intake_can_open_details_and_validation_stays_strict(): void
    {
        config()->set('policyflow.public_demo_enabled', true);

        $payload = [
            'client_request_id' => 'DEMO-12345678-1234-1234-1234-123456789abc',
            'agent_id' => 'DEMO-AGENT',
            'customer_name' => 'Demo Traveler',
            'date_of_birth' => '1990-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ];

        $this->postJson('/api/policy-requests', [...$payload, 'trip_end' => '2026-11-09'])
            ->assertUnprocessable()->assertJsonValidationErrors('trip_end');
        $id = $this->postJson('/api/policy-requests', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'VALIDATED')->json('data.id');
        $this->postJson('/api/policy-requests', $payload)
            ->assertOk()->assertJsonPath('data.id', $id);
        $this->get("/policies/{$id}")->assertOk();
        $this->getJson("/api/policy-requests/{$id}")->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonStructure(['data' => ['state_history', 'issuance_attempts', 'provider_events']]);
        $this->assertDatabaseCount('policy_requests', 1);
    }

    public function test_all_public_mutations_are_blocked_when_demo_is_disabled(): void
    {
        $policy = $this->policy('DEMO-12345678-1234-1234-1234-123456789abc', 'DEMO-AGENT', 'Demo Traveler');

        foreach ([
            '/api/policy-requests',
            "/api/policy-requests/{$policy->id}/submit",
            "/api/policy-requests/{$policy->id}/retry",
            "/api/policy-requests/{$policy->id}/reconcile",
            '/api/mock-insurer/callback',
            "/api/mock-insurer/policy-requests/{$policy->id}/dispatch-callbacks",
        ] as $path) {
            $this->postJson($path, [])->assertForbidden();
        }

        $this->getJson('/api/mock-insurer/status/unknown')->assertForbidden();
    }

    public function test_public_demo_still_cannot_modify_a_non_demo_policy(): void
    {
        config()->set('policyflow.public_demo_enabled', true);
        $private = $this->policy('PRIVATE-1', 'PRIVATE-AGENT', 'Private Person');
        $this->postJson("/api/policy-requests/{$private->id}/submit", ['scenario' => 'SUCCESS'])
            ->assertNotFound();
        $this->assertSame('VALIDATED', $private->fresh()->status);
    }

    public function test_public_demo_rejects_oversized_and_malformed_json(): void
    {
        config()->set('policyflow.public_demo_enabled', true);

        $this->call('POST', '/api/policy-requests', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], str_repeat('x', 8193))->assertStatus(413);

        $this->call('POST', '/api/policy-requests', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{invalid')->assertStatus(400);
    }

    public function test_read_only_diagnostic_confirms_the_intake_schema_without_exposing_configuration(): void
    {
        config()->set('policyflow.public_demo_enabled', true);
        config()->set('logging.default', 'stderr');

        $this->artisan('policyflow:diagnose')
            ->expectsOutput('database_connected: yes')
            ->expectsOutput('intake_schema_ready: yes')
            ->assertExitCode(0);
    }
}
