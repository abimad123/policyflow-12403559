<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PolicyRequestIntakeTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $changes = []): array
    {
        return array_replace([
            'client_request_id' => 'AGT-2026-0001',
            'agent_id' => 'AGENT-17',
            'customer_name' => 'Sample Customer',
            'date_of_birth' => '1998-04-10',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ], $changes);
    }

    public function test_creates_and_persists_a_validated_request_in_postgresql(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertSame('policyflow_test', DB::selectOne('SELECT current_database() AS name')->name);

        $response = $this->postJson('/api/policy-requests', $this->payload());

        $response->assertCreated()
            ->assertJsonStructure(['data' => [
                'id', 'client_request_id', 'agent_id', 'customer_name', 'date_of_birth',
                'destination', 'trip_start', 'trip_end', 'plan_code', 'sum_insured',
                'premium_amount', 'status', 'created_at', 'updated_at',
            ]])
            ->assertJsonPath('data.premium_amount', '225.00')
            ->assertJsonPath('data.status', PolicyRequest::STATUS_VALIDATED)
            ->assertJsonPath('data.trip_start', '2026-11-10')
            ->assertJsonPath('data.sum_insured', 300000);

        $this->assertDatabaseHas('policy_requests', [
            'id' => $response->json('data.id'),
            'agent_id' => 'AGENT-17',
            'client_request_id' => 'AGT-2026-0001',
            'premium_amount' => '225.00',
            'status' => PolicyRequest::STATUS_VALIDATED,
        ]);
    }

    public function test_duplicate_returns_the_original_snapshot_without_creating_another_row(): void
    {
        $first = $this->postJson('/api/policy-requests', $this->payload());
        $second = $this->postJson('/api/policy-requests', $this->payload([
            'customer_name' => 'Different Name',
            'plan_code' => 'PREMIUM',
        ]));

        $first->assertCreated();
        $second->assertOk()->assertExactJson($first->json());
        $this->assertDatabaseCount('policy_requests', 1);
        $this->assertSame('225.00', PolicyRequest::firstOrFail()->premium_amount);
    }

    public function test_duplicate_after_issuance_returns_same_record_with_current_status_and_original_premium(): void
    {
        $first = $this->postJson('/api/policy-requests', $this->payload())->assertCreated()->json('data');
        $this->postJson("/api/policy-requests/{$first['id']}/submit")->assertOk();

        $duplicate = $this->postJson('/api/policy-requests', $this->payload([
            'customer_name' => 'Changed Name',
            'plan_code' => 'PREMIUM',
        ]))->assertOk()->json('data');

        $this->assertSame($first['id'], $duplicate['id']);
        $this->assertSame($first['premium_amount'], $duplicate['premium_amount']);
        $this->assertSame($first['customer_name'], $duplicate['customer_name']);
        $this->assertSame('ISSUED', $duplicate['status']);
        $this->assertDatabaseCount('policy_requests', 1);
        $this->assertDatabaseCount('mock_provider_policies', 1);
    }

    public function test_same_client_request_id_for_another_agent_is_a_new_request(): void
    {
        $this->postJson('/api/policy-requests', $this->payload())->assertCreated();
        $this->postJson('/api/policy-requests', $this->payload(['agent_id' => 'AGENT-18']))->assertCreated();

        $this->assertDatabaseCount('policy_requests', 2);
    }

    public function test_every_intake_field_is_required(): void
    {
        foreach (array_keys($this->payload()) as $field) {
            $payload = $this->payload();
            unset($payload[$field]);

            $this->postJson('/api/policy-requests', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->assertDatabaseCount('policy_requests', 0);
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_values_return_422(string $field, mixed $value): void
    {
        $this->postJson('/api/policy-requests', $this->payload([$field => $value]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('policy_requests', 0);
    }

    public static function invalidInputs(): array
    {
        return [
            'unsupported plan' => ['plan_code', 'GOLD'],
            'unsupported destination' => ['destination', 'MARS'],
            'unsupported coverage' => ['sum_insured', 200000],
            'noninteger coverage' => ['sum_insured', '100000.50'],
            'trip ends before start' => ['trip_end', '2026-11-09'],
            'birth equals trip start' => ['date_of_birth', '2026-11-10'],
            'birth after trip start' => ['date_of_birth', '2026-11-11'],
            'invalid birth date' => ['date_of_birth', '1998-02-30'],
            'invalid trip start format' => ['trip_start', '11/10/2026'],
            'invalid trip end date' => ['trip_end', '2026-11-31'],
            'blank agent' => ['agent_id', ''],
            'blank client id' => ['client_request_id', ''],
            'blank customer' => ['customer_name', ''],
        ];
    }

    public function test_api_validation_errors_are_json_even_without_accept_header(): void
    {
        $this->post('/api/policy-requests', $this->payload(['plan_code' => 'GOLD']))
            ->assertUnprocessable()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonValidationErrors('plan_code');
    }

    public function test_database_enforces_the_composite_unique_key(): void
    {
        $constraint = DB::selectOne("SELECT conname FROM pg_constraint WHERE conrelid = 'policy_requests'::regclass AND contype = 'u' AND pg_get_constraintdef(oid) LIKE '%agent_id, client_request_id%'");

        $this->assertNotNull($constraint);
    }

    public function test_unique_constraint_fallback_can_read_the_winning_row(): void
    {
        $first = $this->postJson('/api/policy-requests', $this->payload())->json('data');

        // This is the same insert-first path firstOrCreate uses after a competing lookup.
        $resolved = PolicyRequest::query()->createOrFirst([
            'agent_id' => 'AGENT-17',
            'client_request_id' => 'AGT-2026-0001',
        ], array_merge($this->payload(), ['premium_amount' => '999.00', 'status' => 'VALIDATED']));

        $this->assertSame($first['id'], $resolved->id);
        $this->assertSame('225.00', $resolved->premium_amount);
        $this->assertDatabaseCount('policy_requests', 1);
    }
}
