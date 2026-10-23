<?php

namespace App\Services;

use App\Models\PolicyRequest;
use Illuminate\Support\Facades\DB;

class PolicyRequestIntake
{
    public function __construct(private readonly PremiumCalculator $calculator) {}

    public function createOrFind(array $input): PolicyRequest
    {
        $identity = [
            'agent_id' => $input['agent_id'],
            'client_request_id' => $input['client_request_id'],
        ];

        return DB::transaction(function () use ($identity, $input) {
            $request = PolicyRequest::query()->firstOrCreate($identity, function () use ($input) {
                return array_merge($input, [
                    'premium_amount' => $this->calculator->calculate($input),
                    'status' => PolicyRequest::STATUS_VALIDATED,
                ]);
            });

            if ($request->wasRecentlyCreated) {
                $request->stateHistory()->create([
                    'previous_state' => null,
                    'new_state' => PolicyRequest::STATUS_VALIDATED,
                    'context' => ['source' => 'intake'],
                    'occurred_at' => now(),
                ]);
            }

            return $request->refresh();
        });
    }
}
