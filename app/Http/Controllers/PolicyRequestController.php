<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePolicyRequestRequest;
use App\Models\PolicyRequest;
use App\Services\PolicyRequestIntake;
use App\Support\DemoMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PolicyRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in([
                PolicyRequest::STATUS_RECEIVED,
                PolicyRequest::STATUS_VALIDATED,
                PolicyRequest::STATUS_SUBMITTED,
                PolicyRequest::STATUS_PENDING_CONFIRMATION,
                PolicyRequest::STATUS_ISSUED,
                PolicyRequest::STATUS_FAILED,
                PolicyRequest::STATUS_NEEDS_RECONCILIATION,
            ])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        if (app()->environment('production') && ! DemoMode::public()) {
            return response()->json([
                'data' => [],
                'pagination' => ['current_page' => 1, 'last_page' => 1, 'per_page' => $filters['per_page'] ?? 15, 'total' => 0],
                'demo_enabled' => false,
            ]);
        }

        $page = PolicyRequest::query()
            ->when(app()->environment('production'), fn ($query) => DemoMode::syntheticPolicies($query))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15);

        return response()->json([
            'data' => $page->items(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        abort_if(app()->environment('production') && ! DemoMode::public(), 404);

        return response()->json([
            'data' => PolicyRequest::query()
                ->when(app()->environment('production'), fn ($query) => DemoMode::syntheticPolicies($query))
                ->with(['stateHistory', 'issuanceAttempts', 'providerEvents'])
                ->findOrFail($id),
        ]);
    }

    public function store(StorePolicyRequestRequest $request, PolicyRequestIntake $intake): JsonResponse
    {
        $policyRequest = $intake->createOrFind($request->validated());

        return response()->json(
            ['data' => $policyRequest],
            $policyRequest->wasRecentlyCreated ? 201 : 200,
        );
    }
}
