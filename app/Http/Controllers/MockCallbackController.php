<?php

namespace App\Http\Controllers;

use App\Exceptions\SubmissionConflict;
use App\Models\PolicyRequest;
use App\Http\Requests\MockCallbackRequest;
use App\Services\MockCallbackDispatcher;
use App\Services\MockInsurer;
use App\Services\PolicyCallback;
use App\Support\DemoMode;
use Illuminate\Http\JsonResponse;

class MockCallbackController extends Controller
{
    public function status(MockInsurer $insurer, string $providerReference): JsonResponse
    {
        abort_unless(DemoMode::available(), 404);

        $policy = $insurer->lookup($providerReference);
        abort_if($policy === null, 404);
        if (app()->environment('production')) {
            abort_unless(DemoMode::syntheticPolicy(PolicyRequest::findOrFail($policy->policy_request_id)), 404);
        }

        return response()->json(['data' => $policy]);
    }

    public function dispatch(MockCallbackDispatcher $dispatcher, int $id): JsonResponse
    {
        abort_unless(DemoMode::available(), 404);

        try {
            $result = $dispatcher->dispatch($id);
        } catch (SubmissionConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json($result);
    }

    public function store(MockCallbackRequest $request, PolicyCallback $callback): JsonResponse
    {
        if (app()->environment('production')) {
            $providerPolicy = app(MockInsurer::class)->lookup($request->validated('provider_reference'));
            abort_if($providerPolicy === null, 404);
            abort_unless(DemoMode::syntheticPolicy(PolicyRequest::findOrFail($providerPolicy->policy_request_id)), 404);
        }

        try {
            $result = $callback->receive($request->validated());
        } catch (SubmissionConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json($result);
    }
}
