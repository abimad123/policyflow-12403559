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
        config()->set('policyflow.demo_write_token', 'test-write-token-with-at-least-32-characters');
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
        $this->get('/')->assertOk()->assertSee('Public browsing is available');
        $this->getJson('/api/policy-requests')->assertOk()
            ->assertJsonPath('pagination.total', 0)
            ->assertJsonPath('demo_enabled', false);
        $this->get('/policies/new')->assertNotFound();
        $this->get('/policies/1')->assertNotFound();
        $this->getJson('/api/policy-requests/1')->assertNotFound();
        $this->postJson('/api/policy-requests', [])->assertForbidden();
        $this->postJson('/api/mock-insurer/callback', [])->assertForbidden();
    }

    public function test_enabled_demo_public_reads_only_synthetic_records_and_hides_token(): void
    {
        config()->set('policyflow.public_demo_enabled', true);
        $synthetic = $this->policy('DEMO-12345678-1234-1234-1234-123456789abc', 'DEMO-AGENT', 'Demo Traveler');
        $private = $this->policy('PRIVATE-1', 'PRIVATE-AGENT', 'Private Person');

        $this->get('/')->assertOk()->assertSee('Evaluator write token')
            ->assertDontSee('test-write-token-with-at-least-32-characters');
        $this->getJson('/api/policy-requests')->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.id', $synthetic->id)
            ->assertDontSee('Private Person');
        $this->get('/policies/new')->assertOk();
        $this->get("/policies/{$synthetic->id}")->assertOk();
        $this->getJson("/api/policy-requests/{$synthetic->id}")->assertOk();
        $this->getJson("/api/policy-requests/{$private->id}")->assertNotFound();
    }

    public function test_all_public_mutations_require_a_configured_write_token(): void
    {
        config()->set('policyflow.public_demo_enabled', true);
        $policy = $this->policy('DEMO-12345678-1234-1234-1234-123456789abc', 'DEMO-AGENT', 'Demo Traveler');

        foreach ([
            '/api/policy-requests',
            "/api/policy-requests/{$policy->id}/submit",
            "/api/policy-requests/{$policy->id}/retry",
            "/api/policy-requests/{$policy->id}/reconcile",
            '/api/mock-insurer/callback',
            "/api/mock-insurer/policy-requests/{$policy->id}/dispatch-callbacks",
        ] as $path) {
            $this->withoutHeader('X-PolicyFlow-Demo-Token')->postJson($path, [])->assertForbidden();
            $this->withHeader('X-PolicyFlow-Demo-Token', 'wrong-token')
                ->postJson($path, [])->assertForbidden();
        }

        $this->getJson('/api/mock-insurer/status/unknown')->assertForbidden();
        config()->set('policyflow.demo_write_token', null);
        $this->withHeader('X-PolicyFlow-Demo-Token', 'test-write-token-with-at-least-32-characters')
            ->postJson('/api/policy-requests', [])->assertForbidden();
    }

    public function test_valid_token_still_cannot_modify_a_non_demo_policy(): void
    {
        config()->set('policyflow.public_demo_enabled', true);
        $private = $this->policy('PRIVATE-1', 'PRIVATE-AGENT', 'Private Person');
        $this->withHeader('X-PolicyFlow-Demo-Token', 'test-write-token-with-at-least-32-characters')
            ->postJson("/api/policy-requests/{$private->id}/submit", ['scenario' => 'SUCCESS'])
            ->assertNotFound();
        $this->assertSame('VALIDATED', $private->fresh()->status);
    }
}
