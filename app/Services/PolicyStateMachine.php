<?php

namespace App\Services;

use App\Exceptions\InvalidPolicyTransition;
use App\Models\PolicyRequest;

class PolicyStateMachine
{
    private const ALLOWED = [
        PolicyRequest::STATUS_RECEIVED => [PolicyRequest::STATUS_VALIDATED],
        PolicyRequest::STATUS_VALIDATED => [PolicyRequest::STATUS_SUBMITTED],
        PolicyRequest::STATUS_SUBMITTED => [
            PolicyRequest::STATUS_PENDING_CONFIRMATION,
            PolicyRequest::STATUS_ISSUED,
            PolicyRequest::STATUS_FAILED,
            PolicyRequest::STATUS_NEEDS_RECONCILIATION,
        ],
        PolicyRequest::STATUS_PENDING_CONFIRMATION => [
            PolicyRequest::STATUS_ISSUED,
            PolicyRequest::STATUS_FAILED,
            PolicyRequest::STATUS_NEEDS_RECONCILIATION,
        ],
        PolicyRequest::STATUS_NEEDS_RECONCILIATION => [
            PolicyRequest::STATUS_PENDING_CONFIRMATION,
            PolicyRequest::STATUS_ISSUED,
            PolicyRequest::STATUS_FAILED,
        ],
        PolicyRequest::STATUS_FAILED => [PolicyRequest::STATUS_SUBMITTED],
        PolicyRequest::STATUS_ISSUED => [],
    ];

    public function transition(PolicyRequest $request, string $nextState, array $context = []): void
    {
        $previousState = $request->status;

        if (! in_array($nextState, self::ALLOWED[$previousState] ?? [], true)) {
            throw new InvalidPolicyTransition("Cannot transition from {$previousState} to {$nextState}.");
        }

        $request->status = $nextState;
        $request->save();
        $request->stateHistory()->create([
            'previous_state' => $previousState,
            'new_state' => $nextState,
            'context' => $context,
            'occurred_at' => now(),
        ]);
    }
}
