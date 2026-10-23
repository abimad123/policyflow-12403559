<?php

namespace App\Services;

use App\Models\PolicyRequest;

class PolicyRequestIntake
{
    public function __construct(private readonly PremiumCalculator $calculator) {}

    public function createOrFind(array $input): PolicyRequest
    {
        $identity = [
            'agent_id' => $input['agent_id'],
            'client_request_id' => $input['client_request_id'],
        ];

        return PolicyRequest::query()->firstOrCreate($identity, function () use ($input) {
            return array_merge($input, [
                'premium_amount' => $this->calculator->calculate($input),
                'status' => PolicyRequest::STATUS_VALIDATED,
            ]);
        });
    }
}
