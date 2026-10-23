<?php

namespace Tests\Feature;

use App\Exceptions\InvalidPolicyTransition;
use App\Models\PolicyRequest;
use App\Services\MockInsurer;
use App\Services\PolicyStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PolicySubmissionTest extends TestCase
{
    use RefreshDatabase;

    private function createRequest(string $clientRequestId = 'REQ-1'): int
    {
        return $this->postJson('/api/policy-requests', [
            'client_request_id' => $clientRequestId,
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

    public function test_successful_submission_issues_once_and_records_every_transition(): void
    {
        $id = $this->createRequest();

        $response = $this->postJson("/api/policy-requests/{$id}/submit");

        $response->assertOk()
            ->assertJsonPath('data.status', PolicyRequest::STATUS_ISSUED)
            ->assertJsonPath('data.submission_attempt_count', 1)
            ->assertJsonPath('data.premium_amount', '225.00')
            ->assertJsonPath('data.last_provider_response.outcome', 'SUCCESS')
            ->assertJsonPath('data.issuance_attempts.0.result', 'SUCCESS')
            ->assertJsonCount(1, 'data.issuance_attempts')
            ->assertJsonCount(3, 'data.state_history');

        $data = $response->json('data');
        $this->assertMatchesRegularExpression('/^PF-\d{10}$/', $data['policy_number']);
        $this->assertMatchesRegularExpression('/^MOCK-REF-\d{10}$/', $data['provider_reference']);
        $this->assertSame(
            [PolicyRequest::STATUS_VALIDATED, PolicyRequest::STATUS_SUBMITTED, PolicyRequest::STATUS_ISSUED],
            array_column($data['state_history'], 'new_state'),
        );
        $this->assertSame(
            [null, PolicyRequest::STATUS_VALIDATED, PolicyRequest::STATUS_SUBMITTED],
            array_column($data['state_history'], 'previous_state'),
        );
        $this->assertNotEmpty($data['state_history'][2]['occurred_at']);
        $this->assertSame(1, DB::table('issuance_attempts')->where('policy_request_id', $id)->count());
    }

    public function test_temporary_failure_is_recorded_without_a_policy_number(): void
    {
        $id = $this->createRequest();

        $response = $this->postJson("/api/policy-requests/{$id}/submit", [
            'scenario' => 'TEMPORARY_FAILURE',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', PolicyRequest::STATUS_FAILED)
            ->assertJsonPath('data.submission_attempt_count', 1)
            ->assertJsonPath('data.policy_number', null)
            ->assertJsonPath('data.provider_reference', null)
            ->assertJsonPath('data.premium_amount', '225.00')
            ->assertJsonPath('data.last_provider_response.error_code', 'MOCK_TEMPORARILY_UNAVAILABLE')
            ->assertJsonPath('data.issuance_attempts.0.result', 'TEMPORARY_FAILURE')
            ->assertJsonPath('data.state_history.2.new_state', PolicyRequest::STATUS_FAILED);

        $this->postJson("/api/policy-requests/{$id}/submit")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Submission is not allowed from status FAILED.');
        $this->assertDatabaseCount('issuance_attempts', 1);
    }

    public function test_missing_or_invalid_request_id_returns_404(): void
    {
        $this->postJson('/api/policy-requests/999999/submit')->assertNotFound();
        $this->postJson('/api/policy-requests/not-an-id/submit')->assertNotFound();
    }

    #[DataProvider('blockedStates')]
    public function test_only_validated_requests_can_be_submitted(string $status): void
    {
        $id = $this->createRequest();
        PolicyRequest::findOrFail($id)->forceFill(['status' => $status])->save();

        $this->postJson("/api/policy-requests/{$id}/submit")
            ->assertStatus(409)
            ->assertJsonPath('message', "Submission is not allowed from status {$status}.");

        $this->assertSame(0, PolicyRequest::findOrFail($id)->submission_attempt_count);
        $this->assertDatabaseCount('issuance_attempts', 0);
    }

    public static function blockedStates(): array
    {
        return [
            'received' => [PolicyRequest::STATUS_RECEIVED],
            'submitted' => [PolicyRequest::STATUS_SUBMITTED],
            'pending confirmation' => [PolicyRequest::STATUS_PENDING_CONFIRMATION],
            'needs reconciliation' => [PolicyRequest::STATUS_NEEDS_RECONCILIATION],
            'failed' => [PolicyRequest::STATUS_FAILED],
        ];
    }

    public function test_issued_request_cannot_regress_and_repeat_submit_is_idempotent(): void
    {
        $insurer = new class extends MockInsurer
        {
            public int $calls = 0;

            public function submit(PolicyRequest $request, string $scenario): array
            {
                $this->calls++;

                return parent::submit($request, $scenario);
            }
        };
        $this->app->instance(MockInsurer::class, $insurer);

        $id = $this->createRequest();
        $first = $this->postJson("/api/policy-requests/{$id}/submit")->assertOk()->json('data');
        $second = $this->postJson("/api/policy-requests/{$id}/submit", ['scenario' => 'TEMPORARY_FAILURE'])->assertOk()->json('data');

        $this->assertSame($first, $second);
        $this->assertSame(1, $insurer->calls);
        $this->assertDatabaseCount('issuance_attempts', 1);
        $this->assertDatabaseCount('policy_state_histories', 3);

        try {
            app(PolicyStateMachine::class)->transition(PolicyRequest::findOrFail($id), PolicyRequest::STATUS_SUBMITTED);
            $this->fail('ISSUED must be terminal.');
        } catch (InvalidPolicyTransition $exception) {
            $this->assertSame('Cannot transition from ISSUED to SUBMITTED.', $exception->getMessage());
        }

        $this->assertSame(PolicyRequest::STATUS_ISSUED, PolicyRequest::findOrFail($id)->status);
        $this->assertDatabaseCount('policy_state_histories', 3);
    }

    public function test_confirmed_policies_have_distinct_numbers_and_a_database_unique_constraint(): void
    {
        $firstId = $this->createRequest('REQ-1');
        $secondId = $this->createRequest('REQ-2');

        $first = $this->postJson("/api/policy-requests/{$firstId}/submit")->assertOk()->json('data.policy_number');
        $second = $this->postJson("/api/policy-requests/{$secondId}/submit")->assertOk()->json('data.policy_number');

        $this->assertNotSame($first, $second);
        $this->assertNotNull(DB::selectOne("SELECT conname FROM pg_constraint WHERE conrelid = 'policy_requests'::regclass AND contype = 'u' AND pg_get_constraintdef(oid) LIKE '%policy_number%'"));
    }

    public function test_unsupported_scenario_is_rejected_before_submission(): void
    {
        $id = $this->createRequest();

        $this->postJson("/api/policy-requests/{$id}/submit", ['scenario' => 'UNKNOWN_SCENARIO'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scenario');

        $this->assertSame(PolicyRequest::STATUS_VALIDATED, PolicyRequest::findOrFail($id)->status);
        $this->assertDatabaseCount('issuance_attempts', 0);
    }
}
