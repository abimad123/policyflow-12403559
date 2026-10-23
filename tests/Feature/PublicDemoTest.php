<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicDemoTest extends TestCase
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
        config()->set('policyflow.public_demo_enabled', true);
        config()->set('policyflow.demo_write_token', 'test-write-token-with-at-least-32-characters');
        $this->withHeader('X-PolicyFlow-Demo-Token', 'test-write-token-with-at-least-32-characters');
    }

    protected function tearDown(): void
    {
        app()->detectEnvironment(fn () => $this->originalEnvironment);
        parent::tearDown();
    }

    private function intake(): int
    {
        return $this->postJson('/api/policy-requests', [
            'client_request_id' => 'DEMO-'.fake()->uuid(),
            'agent_id' => 'DEMO-AGENT',
            'customer_name' => 'Demo Traveler',
            'date_of_birth' => '1998-04-10',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ])->assertCreated()->json('data.id');
    }

    public function test_public_demo_is_opt_in_and_requires_debug_off(): void
    {
        config()->set('policyflow.public_demo_enabled', false);
        $this->get('/')->assertOk();
        $this->get('/policies/new')->assertNotFound();
        $this->postJson('/api/mock-insurer/callback', [])->assertForbidden();
        config()->set('policyflow.public_demo_enabled', true);
        config()->set('app.debug', true);
        $this->get('/')->assertOk();
        $this->get('/policies/new')->assertNotFound();
        config()->set('app.debug', false);
        $this->get('/')->assertOk()->assertSee('Mock demo');
        $this->get('/policies/new')->assertOk()->assertSee('Never enter real customer data');
        $this->get('/policies/1')->assertOk();
    }

    public function test_public_demo_rejects_non_synthetic_intake(): void
    {
        $this->postJson('/api/policy-requests', [
            'client_request_id' => 'PERSON-1',
            'agent_id' => 'REAL-AGENT',
            'customer_name' => 'Real Person',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['client_request_id', 'agent_id', 'customer_name']);
    }

    public function test_all_public_mock_scenarios_and_recovery_actions(): void
    {
        $success = $this->intake();
        $this->postJson("/api/policy-requests/{$success}/submit", ['scenario' => 'SUCCESS'])
            ->assertOk()->assertJsonPath('data.status', 'ISSUED');

        $failed = $this->intake();
        $this->postJson("/api/policy-requests/{$failed}/submit", ['scenario' => 'TEMPORARY_FAILURE'])
            ->assertOk()->assertJsonPath('data.status', 'FAILED');
        $this->postJson("/api/policy-requests/{$failed}/retry", ['scenario' => 'SUCCESS', 'retry_key' => 'demo-retry-1'])
            ->assertOk()->assertJsonPath('data.status', 'ISSUED');

        $timeout = $this->intake();
        $this->postJson("/api/policy-requests/{$timeout}/submit", ['scenario' => 'TIMEOUT_AFTER_ISSUE'])
            ->assertOk()->assertJsonPath('data.status', 'NEEDS_RECONCILIATION');
        $this->postJson("/api/policy-requests/{$timeout}/reconcile")
            ->assertOk()->assertJsonPath('data.status', 'ISSUED');

        foreach (['DELAYED_CALLBACK', 'DUPLICATE_CALLBACK'] as $scenario) {
            $id = $this->intake();
            $this->postJson("/api/policy-requests/{$id}/submit", ['scenario' => $scenario])
                ->assertOk()->assertJsonPath('data.status', 'PENDING_CONFIRMATION');
            $this->postJson("/api/mock-insurer/policy-requests/{$id}/dispatch-callbacks")
                ->assertOk()->assertJsonPath('data.status', 'ISSUED');
            $this->postJson("/api/mock-insurer/policy-requests/{$id}/dispatch-callbacks")
                ->assertOk()->assertJsonPath('data.status', 'ISSUED');
            $this->assertSame(1, PolicyRequest::findOrFail($id)->submission_attempt_count);
        }
    }

    public function test_public_demo_write_rate_limit_returns_429(): void
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->postJson('/api/policy-requests', [])->assertUnprocessable();
        }

        $this->postJson('/api/policy-requests', [])->assertStatus(429);
    }

    public function test_public_demo_callback_receiver_and_status_lookup_are_available_and_idempotent(): void
    {
        $id = $this->intake();
        $submitted = $this->postJson("/api/policy-requests/{$id}/submit", ['scenario' => 'DELAYED_CALLBACK'])
            ->assertOk()->json('data');
        $reference = $submitted['provider_reference'];
        $provider = $this->getJson("/api/mock-insurer/status/{$reference}")
            ->assertOk()->json('data');

        $callback = [
            'event_id' => 'DEMO-EVENT-1',
            'provider_reference' => $reference,
            'event_type' => 'ISSUED',
            'policy_number' => $provider['policy_number'],
        ];

        $this->postJson('/api/mock-insurer/callback', $callback)
            ->assertOk()->assertJsonPath('data.status', 'ISSUED')->assertJsonPath('duplicate', false);
        $this->postJson('/api/mock-insurer/callback', $callback)
            ->assertOk()->assertJsonPath('data.status', 'ISSUED')->assertJsonPath('duplicate', true);
        $this->postJson("/api/policy-requests/{$id}/submit", ['scenario' => 'SUCCESS'])
            ->assertOk()->assertJsonPath('data.status', 'ISSUED');
        $this->assertSame(1, PolicyRequest::findOrFail($id)->submission_attempt_count);
    }
}
