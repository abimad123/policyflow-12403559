<?php

namespace App\Services;

use App\Exceptions\SubmissionConflict;
use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class PolicyCallback
{
    public function __construct(
        private readonly MockInsurer $insurer,
        private readonly PolicyStateMachine $states,
    ) {}

    public function receive(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $providerPolicy = $this->insurer->lookup($input['provider_reference']);
            if ($providerPolicy === null) {
                throw (new ModelNotFoundException)->setModel(PolicyRequest::class);
            }

            $request = PolicyRequest::query()->lockForUpdate()->findOrFail($providerPolicy->policy_request_id);
            $existingEvent = ProviderEvent::query()->where('event_id', $input['event_id'])->first();
            if ($existingEvent !== null) {
                if ($existingEvent->policy_request_id !== $request->id
                    || $existingEvent->provider_reference !== $input['provider_reference']
                    || $existingEvent->policy_number !== $input['policy_number']
                    || $existingEvent->event_type !== $input['event_type']) {
                    throw new SubmissionConflict('Provider event ID conflicts with an earlier event.');
                }

                return $this->result($request, $existingEvent, true);
            }

            if ($providerPolicy->status !== 'ISSUED'
                || $request->provider_reference !== $providerPolicy->provider_reference
                || $providerPolicy->policy_number !== $input['policy_number']) {
                throw new SubmissionConflict('Callback does not match the recorded provider policy.');
            }

            $alreadyIssued = $request->status === PolicyRequest::STATUS_ISSUED;
            if ($alreadyIssued && $request->policy_number !== $input['policy_number']) {
                throw new SubmissionConflict('Callback conflicts with the issued policy.');
            }

            if (! $alreadyIssued && ! in_array($request->status, [
                PolicyRequest::STATUS_PENDING_CONFIRMATION,
                PolicyRequest::STATUS_NEEDS_RECONCILIATION,
            ], true)) {
                throw new SubmissionConflict("Callback is not allowed from status {$request->status}.");
            }

            $event = $request->providerEvents()->create([
                ...$input,
                'applied' => ! $alreadyIssued,
                'payload' => $input,
            ]);

            if (! $alreadyIssued) {
                $request->policy_number = $input['policy_number'];
                $request->last_provider_response = ['outcome' => 'CALLBACK_CONFIRMED', 'event_id' => $input['event_id']];
                $request->save();
                $this->states->transition($request, PolicyRequest::STATUS_ISSUED, [
                    'source' => 'callback', 'event_id' => $input['event_id'],
                ]);
            }

            return $this->result($request->refresh(), $event, $alreadyIssued);
        });
    }

    private function result(PolicyRequest $request, $event, bool $duplicate): array
    {
        return [
            'data' => $request->load(['stateHistory', 'issuanceAttempts', 'providerEvents']),
            'event_id' => $event->event_id,
            'duplicate' => $duplicate,
        ];
    }
}
