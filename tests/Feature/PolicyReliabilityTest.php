<?php

namespace Tests\Feature;

use App\Models\MockProviderPolicy;
use App\Models\PolicyRequest;
use App\Services\MockInsurer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PolicyReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private function intake(string $clientId = 'REQ-1'): int
    {
        return $this->postJson('/api/policy-requests', [
            'client_request_id' => $clientId,
            'agent_id' => 'AGENT-17',
            'customer_name' => 'Sample Customer',
            'date_of_birth' => '1998-04-10',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ])->assertCreated()->json('data.id');
    }

    private function callbackPayload(int $id, string $eventId = 'EVENT-1'): array
    {
        $provider = PolicyRequest::findOrFail($id)->mockProviderPolicy()->firstOrFail();

        return [
            'event_id' => $eventId,
            'provider_reference' => $provider->provider_reference,
            'event_type' => 'ISSUED',
            'policy_number' => $provider->policy_number,
        ];
    }

    public function test_timeout_after_issue_is_uncertain_until_reconciliation_and_never_resubmits(): void
    {
        $id = $this->intake();
        $first = $this->postJson("/api/policy-requests/{$id}/submit", ['scenario' => 'TIMEOUT_AFTER_ISSUE'])
            ->assertOk()->assertJsonPath('data.status', 'NEEDS_RECONCILIATION')
            ->assertJsonPath('data.policy_number', null)->json('data');

        $this->assertDatabaseCount('mock_provider_policies', 1);
        $this->postJson("/api/policy-requests/{$id}/submit")
            ->assertStatus(409);

        $this->postJson("/api/policy-requests/{$id}/reconcile")
            ->assertOk()->assertJsonPath('resolved', true)
            ->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.submission_attempt_count', 1);
        $this->postJson("/api/policy-requests/{$id}/reconcile")
            ->assertOk()->assertJsonPath('resolved', true);

        $this->assertSame('225.00', PolicyRequest::findOrFail($id)->premium_amount);
        $this->assertDatabaseCount('mock_provider_policies', 1);
        $this->assertDatabaseCount('issuance_attempts', 1);
        $this->assertDatabaseCount('policy_state_histories', 4);
        $this->assertNotNull($first['provider_reference']);
    }

    public function test_unknown_provider_status_preserves_uncertainty(): void
    {
        $id = $this->intake();
        $this->postJson("/api/policy-requests/{$id}/submit", ['scenario' => 'TIMEOUT_AFTER_ISSUE'])->assertOk();

        $this->app->instance(MockInsurer::class, new class extends MockInsurer
        {
            public function lookup(string $providerReference): ?MockProviderPolicy
            {
                return null;
            }
        });

        $this->postJson("/api/policy-requests/{$id}/reconcile")
            ->assertStatus(202)->assertJsonPath('resolved', false)
            ->assertJsonPath('data.status', 'NEEDS_RECONCILIATION');
        $this->assertDatabaseCount('policy_state_histories', 3);
    }

    public function test_timeout_can_be_confirmed_by_callback_without_a_second_submission(): void
    {
        $id = $this->intake();
        $this->postJson("/api/policy-requests/{$id}/submit", ['scenario' => 'TIMEOUT_AFTER_ISSUE'])->assertOk();
        $payload = $this->callbackPayload($id);

        $this->postJson('/api/mock-insurer/callback', $payload)
            ->assertOk()->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.policy_number', $payload['policy_number']);
        $this->postJson("/api/policy-requests/{$id}/reconcile")
            ->assertOk()->assertJsonPath('data.policy_number', $payload['policy_number']);

        $this->assertDatabaseCount('issuance_attempts', 1);
        $this->assertDatabaseCount('mock_provider_policies', 1);
        $this->assertDatabaseCount('policy_state_histories', 4);
    }

    public function test_delayed_and_duplicate_callbacks_apply_once(): void
    {
        foreach (['DELAYED_CALLBACK', 'DUPLICATE_CALLBACK'] as $scenario) {
            $id = $this->intake($scenario);
            $this->postJson("/api/policy-requests/{$id}/submit", ['scenario' => $scenario])
                ->assertOk()->assertJsonPath('data.status', 'PENDING_CONFIRMATION')
                ->assertJsonPath('data.policy_number', null);

            $payload = $this->callbackPayload($id, 'EVENT-'.$scenario);
            $this->postJson('/api/mock-insurer/callback', $payload)
                ->assertOk()->assertJsonPath('duplicate', false)
                ->assertJsonPath('data.status', 'ISSUED')
                ->assertJsonPath('data.policy_number', $payload['policy_number']);
            $this->postJson('/api/mock-insurer/callback', $payload)
                ->assertOk()->assertJsonPath('duplicate', true);
            $this->postJson('/api/mock-insurer/callback', [...$payload, 'event_id' => 'ANOTHER-'.$scenario])
                ->assertOk()->assertJsonPath('duplicate', true);
        }

        $this->assertDatabaseCount('provider_events', 4);
        $this->assertDatabaseCount('mock_provider_policies', 2);
        $this->assertDatabaseCount('policy_state_histories', 8);
    }

    public function test_mock_dispatch_delivers_three_identical_events_without_duplicate_issuance(): void
    {
        $id = $this->intake();
        $submitted = $this->postJson("/api/policy-requests/{$id}/submit", ['scenario' => 'DUPLICATE_CALLBACK'])
            ->assertOk()->assertJsonPath('data.status', 'PENDING_CONFIRMATION')->json('data');

        $this->getJson('/api/mock-insurer/status/'.$submitted['provider_reference'])
            ->assertOk()->assertJsonPath('data.policy_request_id', $id);

        $this->postJson("/api/mock-insurer/policy-requests/{$id}/dispatch-callbacks")
            ->assertOk()->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonCount(3, 'deliveries')
            ->assertJsonPath('deliveries.0.duplicate', false)
            ->assertJsonPath('deliveries.1.duplicate', true)
            ->assertJsonPath('deliveries.2.duplicate', true);

        $this->postJson("/api/mock-insurer/policy-requests/{$id}/dispatch-callbacks")
            ->assertOk()->assertJsonPath('deliveries.0.duplicate', true);
        $this->assertDatabaseCount('provider_events', 1);
        $this->assertDatabaseCount('mock_provider_policies', 1);
        $this->assertDatabaseCount('issuance_attempts', 1);
        $this->assertDatabaseCount('policy_state_histories', 4);
    }

    public function test_dispatch_without_a_queued_callback_returns_a_meaningful_conflict(): void
    {
        $id = $this->intake();
        $this->postJson("/api/policy-requests/{$id}/submit")
            ->assertOk()->assertJsonPath('data.status', 'ISSUED');

        $this->postJson("/api/mock-insurer/policy-requests/{$id}/dispatch-callbacks")
            ->assertStatus(409)
            ->assertJsonPath('message', 'No mock callback is queued for this request.');

        $this->assertDatabaseCount('provider_events', 0);
        $this->assertDatabaseCount('issuance_attempts', 1);
        $this->assertDatabaseCount('mock_provider_policies', 1);
        $this->assertSame('ISSUED', PolicyRequest::findOrFail($id)->status);
    }

    public function test_invalid_and_conflicting_callbacks_cannot_issue_or_regress(): void
    {
        $id = $this->intake();
        $this->postJson("/api/policy-requests/{$id}/submit", ['scenario' => 'DELAYED_CALLBACK'])->assertOk();
        $payload = $this->callbackPayload($id);

        $this->postJson('/api/mock-insurer/callback', [])->assertUnprocessable();
        $this->postJson('/api/mock-insurer/callback', [...$payload, 'event_type' => 'FAILED'])
            ->assertUnprocessable();
        $this->postJson('/api/mock-insurer/callback', [...$payload, 'provider_reference' => 'UNKNOWN'])
            ->assertNotFound();
        $this->postJson('/api/mock-insurer/callback', [...$payload, 'policy_number' => 'WRONG'])
            ->assertStatus(409);
        $provider = PolicyRequest::findOrFail($id)->mockProviderPolicy()->firstOrFail();
        $provider->update(['status' => 'PENDING']);
        $this->postJson('/api/mock-insurer/callback', $payload)->assertStatus(409);
        $provider->update(['status' => 'ISSUED']);
        $this->assertSame('PENDING_CONFIRMATION', PolicyRequest::findOrFail($id)->status);

        $this->postJson('/api/mock-insurer/callback', $payload)->assertOk();
        $this->postJson('/api/mock-insurer/callback', [...$payload, 'policy_number' => 'WRONG'])
            ->assertStatus(409);
        $this->assertSame($payload['policy_number'], PolicyRequest::findOrFail($id)->policy_number);
        $this->assertDatabaseCount('provider_events', 1);
    }

    public function test_temporary_failure_retry_is_keyed_and_preserves_premium(): void
    {
        $id = $this->intake();
        $this->postJson("/api/policy-requests/{$id}/submit", ['scenario' => 'TEMPORARY_FAILURE'])
            ->assertOk()->assertJsonPath('data.status', 'FAILED');
        $this->postJson("/api/policy-requests/{$id}/retry", ['retry_key' => 'retry-1', 'scenario' => 'TEMPORARY_FAILURE'])
            ->assertOk()->assertJsonPath('data.status', 'FAILED')->assertJsonPath('data.submission_attempt_count', 2);
        $this->postJson("/api/policy-requests/{$id}/retry", ['retry_key' => 'retry-1'])
            ->assertOk()->assertJsonPath('data.submission_attempt_count', 2);
        $issued = $this->postJson("/api/policy-requests/{$id}/retry", ['retry_key' => 'retry-2'])
            ->assertOk()->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.premium_amount', '225.00')
            ->assertJsonPath('data.submission_attempt_count', 3)->json('data');
        $this->postJson("/api/policy-requests/{$id}/retry", ['retry_key' => 'retry-2'])
            ->assertOk()->assertJsonPath('data.policy_number', $issued['policy_number']);
        $this->postJson("/api/policy-requests/{$id}/retry", ['retry_key' => 'retry-3', 'scenario' => 'TEMPORARY_FAILURE'])
            ->assertOk()->assertJsonPath('data.policy_number', $issued['policy_number'])
            ->assertJsonPath('data.submission_attempt_count', 3);

        $this->assertDatabaseCount('issuance_attempts', 3);
        $this->assertDatabaseCount('mock_provider_policies', 1);
        $this->assertDatabaseCount('policy_state_histories', 7);
    }

    public function test_retry_validation_and_read_endpoints(): void
    {
        $id = $this->intake();
        $this->postJson("/api/policy-requests/{$id}/retry", [])->assertUnprocessable();
        $this->postJson("/api/policy-requests/{$id}/retry", ['retry_key' => 'too-early'])->assertStatus(409);
        $this->getJson("/api/policy-requests/{$id}")
            ->assertOk()->assertJsonPath('data.status', 'VALIDATED')
            ->assertJsonCount(1, 'data.state_history');
        $this->getJson('/api/policy-requests?status=VALIDATED&per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('pagination.total', 1);
        $this->getJson('/api/policy-requests?status=NOT_A_STATE')->assertUnprocessable();
        $this->getJson('/api/policy-requests/999999')->assertNotFound();
    }

    public function test_status_filter_and_pagination_keep_stable_order_and_counts(): void
    {
        $issuedId = $this->intake('ISSUED-REQUEST');
        $failedId = $this->intake('FAILED-REQUEST');
        $validatedId = $this->intake('VALIDATED-REQUEST');
        $this->postJson("/api/policy-requests/{$issuedId}/submit")->assertOk();
        $this->postJson("/api/policy-requests/{$failedId}/submit", ['scenario' => 'TEMPORARY_FAILURE'])->assertOk();

        $this->getJson('/api/policy-requests?status=VALIDATED')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $validatedId)
            ->assertJsonPath('pagination.total', 1);
        $this->getJson('/api/policy-requests?status=ISSUED')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $issuedId);

        foreach ([1 => $validatedId, 2 => $failedId, 3 => $issuedId] as $page => $expectedId) {
            $this->getJson("/api/policy-requests?per_page=1&page={$page}")
                ->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $expectedId)
                ->assertJsonPath('pagination.current_page', $page)
                ->assertJsonPath('pagination.last_page', 3)
                ->assertJsonPath('pagination.total', 3);
        }

        $this->getJson('/api/policy-requests?per_page=1&page=4')
            ->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('pagination.total', 3);
        $this->getJson('/api/policy-requests?per_page=101')->assertUnprocessable();
        $this->getJson('/api/policy-requests?page=0')->assertUnprocessable();
    }

    public function test_provider_reference_and_policy_number_have_database_unique_constraints(): void
    {
        $id = $this->intake();
        $this->postJson("/api/policy-requests/{$id}/submit")->assertOk();

        foreach (['provider_reference', 'policy_number'] as $column) {
            $this->assertNotNull(DB::selectOne(
                "SELECT conname FROM pg_constraint WHERE conrelid = 'policy_requests'::regclass AND contype = 'u' AND pg_get_constraintdef(oid) LIKE ?",
                ["%{$column}%"],
            ));
        }
    }
}
