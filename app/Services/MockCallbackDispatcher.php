<?php

namespace App\Services;

use App\Exceptions\SubmissionConflict;
use App\Models\PolicyRequest;

class MockCallbackDispatcher
{
    public function __construct(private readonly PolicyCallback $callback) {}

    public function dispatch(int $id): array
    {
        $request = PolicyRequest::query()->with(['mockProviderPolicy', 'issuanceAttempts'])->findOrFail($id);
        $attempt = $request->issuanceAttempts->last();
        if ($attempt === null || ! in_array($attempt->scenario, [
            MockInsurer::DELAYED_CALLBACK,
            MockInsurer::DUPLICATE_CALLBACK,
        ], true) || $request->mockProviderPolicy === null) {
            throw new SubmissionConflict('No mock callback is queued for this request.');
        }

        $payload = [
            'event_id' => 'MOCK-EVENT-'.$request->id.'-'.$attempt->attempt_number,
            'provider_reference' => $request->mockProviderPolicy->provider_reference,
            'event_type' => 'ISSUED',
            'policy_number' => $request->mockProviderPolicy->policy_number,
        ];

        $deliveries = [];
        $count = $attempt->scenario === MockInsurer::DUPLICATE_CALLBACK ? 3 : 1;
        for ($i = 0; $i < $count; $i++) {
            $result = $this->callback->receive($payload);
            $deliveries[] = ['event_id' => $result['event_id'], 'duplicate' => $result['duplicate']];
        }

        return ['data' => $result['data'], 'deliveries' => $deliveries];
    }
}
