<?php

namespace App\Services;

use App\Models\MockProviderPolicy;
use App\Models\PolicyRequest;
use InvalidArgumentException;

class MockInsurer
{
    public const SUCCESS = 'SUCCESS';

    public const TEMPORARY_FAILURE = 'TEMPORARY_FAILURE';

    public const TIMEOUT_AFTER_ISSUE = 'TIMEOUT_AFTER_ISSUE';

    public const DELAYED_CALLBACK = 'DELAYED_CALLBACK';

    public const DUPLICATE_CALLBACK = 'DUPLICATE_CALLBACK';

    /**
     * A deterministic, local response. Future scenarios can be added without
     * changing the submission transaction or calling a network service.
     */
    public function submit(PolicyRequest $request, string $scenario): array
    {
        // The provider stores one policy per request. A repeated call with the same
        // request ID can only retrieve that policy, never issue a second one.
        $existing = $request->mockProviderPolicy()->first();
        if ($existing !== null) {
            return $this->confirmed($existing);
        }

        return match ($scenario) {
            self::SUCCESS => $this->success($request),
            self::TEMPORARY_FAILURE => [
                'result' => self::TEMPORARY_FAILURE,
                'provider_reference' => null,
                'policy_number' => null,
                'response' => [
                    'outcome' => self::TEMPORARY_FAILURE,
                    'error_code' => 'MOCK_TEMPORARILY_UNAVAILABLE',
                ],
            ],
            self::TIMEOUT_AFTER_ISSUE => $this->uncertain($request),
            self::DELAYED_CALLBACK, self::DUPLICATE_CALLBACK => $this->pending($request, $scenario),
            default => throw new InvalidArgumentException('Unsupported mock scenario.'),
        };
    }

    public function lookup(string $providerReference): ?MockProviderPolicy
    {
        return MockProviderPolicy::query()->where('provider_reference', $providerReference)->first();
    }

    private function issue(PolicyRequest $request): MockProviderPolicy
    {
        $reference = 'MOCK-REF-'.str_pad((string) $request->id, 10, '0', STR_PAD_LEFT);
        $policyNumber = 'PF-'.str_pad((string) $request->id, 10, '0', STR_PAD_LEFT);

        return $request->mockProviderPolicy()->create([
            'provider_reference' => $reference,
            'policy_number' => $policyNumber,
            'status' => 'ISSUED',
        ]);
    }

    private function success(PolicyRequest $request): array
    {
        return $this->confirmed($this->issue($request));
    }

    private function confirmed(MockProviderPolicy $policy): array
    {
        return [
            'result' => self::SUCCESS,
            'provider_reference' => $policy->provider_reference,
            'policy_number' => $policy->policy_number,
            'response' => [
                'outcome' => self::SUCCESS,
                'provider_reference' => $policy->provider_reference,
                'policy_number' => $policy->policy_number,
            ],
        ];
    }

    private function uncertain(PolicyRequest $request): array
    {
        $policy = $this->issue($request);

        return [
            'result' => self::TIMEOUT_AFTER_ISSUE,
            'provider_reference' => $policy->provider_reference,
            'policy_number' => null,
            'response' => ['outcome' => self::TIMEOUT_AFTER_ISSUE],
        ];
    }

    private function pending(PolicyRequest $request, string $scenario): array
    {
        $policy = $this->issue($request);

        return [
            'result' => $scenario,
            'provider_reference' => $policy->provider_reference,
            'policy_number' => null,
            'response' => ['outcome' => 'PENDING_CALLBACK', 'scenario' => $scenario],
        ];
    }
}
