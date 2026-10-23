<?php

namespace App\Http\Controllers;

use App\Exceptions\SubmissionConflict;
use App\Http\Requests\RetryPolicyRequestRequest;
use App\Services\MockInsurer;
use App\Services\PolicyReconciliation;
use App\Services\PolicySubmission;
use Illuminate\Http\JsonResponse;

class PolicyReliabilityController extends Controller
{
    public function retry(RetryPolicyRequestRequest $request, PolicySubmission $submission, int $id): JsonResponse
    {
        try {
            $policyRequest = $submission->retry(
                $id,
                $request->validated('scenario') ?? MockInsurer::SUCCESS,
                $request->validated('retry_key'),
            );
        } catch (SubmissionConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $policyRequest]);
    }

    public function reconcile(PolicyReconciliation $reconciliation, int $id): JsonResponse
    {
        try {
            $result = $reconciliation->reconcile($id);
        } catch (SubmissionConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json($result, $result['resolved'] ? 200 : 202);
    }
}
