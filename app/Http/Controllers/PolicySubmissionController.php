<?php

namespace App\Http\Controllers;

use App\Exceptions\SubmissionConflict;
use App\Http\Requests\SubmitPolicyRequestRequest;
use App\Services\MockInsurer;
use App\Services\PolicySubmission;
use Illuminate\Http\JsonResponse;

class PolicySubmissionController extends Controller
{
    public function store(SubmitPolicyRequestRequest $request, PolicySubmission $submission, int $id): JsonResponse
    {
        try {
            $policyRequest = $submission->submit($id, $request->validated('scenario') ?? MockInsurer::SUCCESS);
        } catch (SubmissionConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $policyRequest]);
    }
}
