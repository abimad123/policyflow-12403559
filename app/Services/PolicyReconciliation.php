<?php

namespace App\Services;

use App\Exceptions\SubmissionConflict;
use App\Models\PolicyRequest;
use Illuminate\Support\Facades\DB;

class PolicyReconciliation
{
    public function __construct(
        private readonly MockInsurer $insurer,
        private readonly PolicyStateMachine $states,
    ) {}

    public function reconcile(int $id): array
    {
        return DB::transaction(function () use ($id) {
            $request = PolicyRequest::query()->lockForUpdate()->findOrFail($id);
            if ($request->status === PolicyRequest::STATUS_ISSUED) {
                return $this->result($request, true);
            }

            if (! in_array($request->status, [
                PolicyRequest::STATUS_PENDING_CONFIRMATION,
                PolicyRequest::STATUS_NEEDS_RECONCILIATION,
            ], true)) {
                throw new SubmissionConflict("Reconciliation is not allowed from status {$request->status}.");
            }

            $providerPolicy = $request->provider_reference === null
                ? null
                : $this->insurer->lookup($request->provider_reference);

            if ($providerPolicy === null) {
                return $this->result($request, false);
            }

            if ($providerPolicy->policy_request_id !== $request->id || $providerPolicy->status !== 'ISSUED') {
                throw new SubmissionConflict('Provider status conflicts with the request.');
            }

            $request->policy_number = $providerPolicy->policy_number;
            $request->last_provider_response = [
                'outcome' => 'RECONCILED_ISSUED',
                'provider_reference' => $providerPolicy->provider_reference,
                'policy_number' => $providerPolicy->policy_number,
            ];
            $request->save();
            $this->states->transition($request, PolicyRequest::STATUS_ISSUED, ['source' => 'reconciliation']);

            return $this->result($request->refresh(), true);
        });
    }

    private function result(PolicyRequest $request, bool $resolved): array
    {
        return [
            'data' => $request->load(['stateHistory', 'issuanceAttempts', 'providerEvents']),
            'resolved' => $resolved,
        ];
    }
}
