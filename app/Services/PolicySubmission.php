<?php

namespace App\Services;

use App\Exceptions\SubmissionConflict;
use App\Models\PolicyRequest;
use Illuminate\Support\Facades\DB;

class PolicySubmission
{
    public function __construct(
        private readonly MockInsurer $insurer,
        private readonly PolicyStateMachine $states,
    ) {}

    public function submit(int $id, string $scenario): PolicyRequest
    {
        return $this->process($id, $scenario, null, false);
    }

    public function retry(int $id, string $scenario, string $retryKey): PolicyRequest
    {
        return $this->process($id, $scenario, $retryKey, true);
    }

    private function process(int $id, string $scenario, ?string $retryKey, bool $retry): PolicyRequest
    {
        return DB::transaction(function () use ($id, $scenario, $retryKey, $retry) {
            $request = PolicyRequest::query()->lockForUpdate()->findOrFail($id);

            if ($request->status === PolicyRequest::STATUS_ISSUED) {
                return $this->withAudit($request);
            }

            if ($retry && $request->issuanceAttempts()->where('retry_key', $retryKey)->exists()) {
                return $this->withAudit($request);
            }

            $expected = $retry ? PolicyRequest::STATUS_FAILED : PolicyRequest::STATUS_VALIDATED;
            if ($request->status !== $expected) {
                throw new SubmissionConflict("Submission is not allowed from status {$request->status}.");
            }

            $this->states->transition($request, PolicyRequest::STATUS_SUBMITTED, [
                'source' => 'submission',
            ]);

            $request->submission_attempt_count++;
            $request->save();

            $result = $this->insurer->submit($request, $scenario);

            $request->issuanceAttempts()->create([
                'attempt_number' => $request->submission_attempt_count,
                'retry_key' => $retryKey,
                'scenario' => $scenario,
                'result' => $result['result'],
                'provider_reference' => $result['provider_reference'],
                'response' => $result['response'],
            ]);

            $request->provider_reference = $result['provider_reference'];
            $request->last_provider_response = $result['response'];
            $request->policy_number = $result['policy_number'];
            $request->save();

            $finalState = match ($result['result']) {
                MockInsurer::SUCCESS => PolicyRequest::STATUS_ISSUED,
                MockInsurer::TEMPORARY_FAILURE => PolicyRequest::STATUS_FAILED,
                MockInsurer::TIMEOUT_AFTER_ISSUE => PolicyRequest::STATUS_NEEDS_RECONCILIATION,
                MockInsurer::DELAYED_CALLBACK, MockInsurer::DUPLICATE_CALLBACK => PolicyRequest::STATUS_PENDING_CONFIRMATION,
            };

            $this->states->transition($request, $finalState, [
                'attempt_number' => $request->submission_attempt_count,
                'result' => $result['result'],
            ]);

            return $this->withAudit($request->refresh());
        });
    }

    private function withAudit(PolicyRequest $request): PolicyRequest
    {
        return $request->load(['stateHistory', 'issuanceAttempts', 'providerEvents']);
    }
}
