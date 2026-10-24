<?php

namespace App\Http\Controllers;

use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use App\Services\IssuanceService;
use App\Services\PremiumCalculatorService;
use App\Services\StateMachineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PolicyRequestController extends Controller
{
    public function __construct(
        protected PremiumCalculatorService $premiumCalculator,
        protected StateMachineService $stateMachine,
        protected IssuanceService $issuanceService
    ) {}

    /**
     * POST /api/policy-requests
     * Create or safely return existing request.
     * HTTP 201 for new, HTTP 200 for safely repeated request.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_request_id' => 'required|string|max:100',
            'agent_id' => 'required|string|max:50',
            'customer_name' => 'required|string|max:255',
            'date_of_birth' => 'required|date|before:today',
            'destination' => ['required', 'string', Rule::in(['DOMESTIC', 'INTERNATIONAL'])],
            'trip_start' => 'required|date',
            'trip_end' => 'required|date|after_or_equal:trip_start',
            'plan_code' => ['required', 'string', Rule::in(['BASIC', 'PLUS', 'PREMIUM'])],
            'sum_insured' => ['required', 'integer', Rule::in([100000, 300000, 500000])],
        ]);

        // Duplicate Intake Reliability: Check if agent_id + client_request_id already exists
        $existing = PolicyRequest::where('agent_id', $validated['agent_id'])
            ->where('client_request_id', $validated['client_request_id'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Policy request already exists for this agent and client_request_id.',
                'is_duplicate' => true,
                'data' => $this->formatPolicyResponse($existing),
            ], 200);
        }

        // Calculate deterministic premium snapshot
        $pricing = $this->premiumCalculator->calculate(
            $validated['plan_code'],
            $validated['sum_insured'],
            $validated['destination'],
            $validated['date_of_birth'],
            $validated['trip_start'],
            $validated['trip_end']
        );

        $isNewlyCreated = false;

        $policyRequest = DB::transaction(function () use ($validated, $pricing, &$isNewlyCreated) {
            // Re-check inside transaction with pessimistic lock to prevent race conditions
            $concurrentExisting = PolicyRequest::where('agent_id', $validated['agent_id'])
                ->where('client_request_id', $validated['client_request_id'])
                ->lockForUpdate()
                ->first();

            if ($concurrentExisting) {
                return $concurrentExisting;
            }

            $record = PolicyRequest::create(array_merge($validated, [
                'premium' => $pricing['premium'],
                'travel_days' => $pricing['travel_days'],
                'age_multiplier' => $pricing['age_multiplier'],
                'destination_multiplier' => $pricing['destination_multiplier'],
                'coverage_multiplier' => $pricing['coverage_multiplier'],
                'base_daily_rate' => $pricing['base_daily_rate'],
                'status' => PolicyRequest::STATUS_RECEIVED,
            ]));

            $isNewlyCreated = true;

            // Transition from RECEIVED to VALIDATED
            return $this->stateMachine->transition(
                $record,
                PolicyRequest::STATUS_VALIDATED,
                'intake_validation_passed',
                $pricing,
                'system'
            );
        });

        $status = $isNewlyCreated ? 201 : 200;

        return response()->json([
            'message' => $isNewlyCreated ? 'Policy request successfully created and validated.' : 'Policy request already existed.',
            'is_duplicate' => !$isNewlyCreated,
            'data' => $this->formatPolicyResponse($policyRequest),
        ], $status);
    }

    /**
     * GET /api/policy-requests/{id}
     * Retrieve status, premium, references, and audit history.
     */
    public function show(string $id): JsonResponse
    {
        $policyRequest = PolicyRequest::with(['stateHistory', 'issuanceAttempts', 'providerEvents'])
            ->where('id', $id)
            ->orWhere('uuid', $id)
            ->firstOrFail();

        return response()->json([
            'data' => $this->formatPolicyResponse($policyRequest, true),
        ], 200);
    }

    /**
     * POST /api/policy-requests/{id}/submit
     * Start or safely resume issuance.
     */
    public function submit(Request $request, string $id): JsonResponse
    {
        $policyRequest = PolicyRequest::where('id', $id)
            ->orWhere('uuid', $id)
            ->firstOrFail();

        $scenario = $request->input('scenario', $request->header('X-Mock-Scenario'));

        $updated = $this->issuanceService->submit($policyRequest, $scenario);

        return response()->json([
            'message' => "Issuance workflow executed. Current status: {$updated->status}",
            'data' => $this->formatPolicyResponse($updated),
        ], 200);
    }

    /**
     * POST /api/mock-insurer/callback
     * Receive simulated provider event callback.
     * Enforces Idempotency via unique event_id constraint.
     */
    public function callback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event_id' => 'required|string|max:100',
            'event_type' => 'required|string|max:50',
            'provider_reference' => 'required|string|max:255',
            'policy_number' => 'nullable|string|max:100',
            'client_request_id' => 'nullable|string',
            'payload' => 'nullable|array',
        ]);

        $eventId = $validated['event_id'];

        // Duplicate Callback Reliability: Check if event_id has already been processed
        $existingEvent = ProviderEvent::where('event_id', $eventId)->first();
        if ($existingEvent) {
            return response()->json([
                'message' => 'Duplicate callback ignored; event already processed.',
                'idempotent' => true,
                'event_id' => $eventId,
                'processed_at' => $existingEvent->processed_at,
            ], 200);
        }

        // Find associated policy request
        $policyRequest = PolicyRequest::where('provider_reference', $validated['provider_reference'])
            ->when(!empty($validated['client_request_id']), function ($query) use ($validated) {
                $query->orWhere('client_request_id', $validated['client_request_id']);
            })
            ->first();

        if (!$policyRequest) {
            return response()->json([
                'message' => 'No policy request found matching provider_reference.',
            ], 404);
        }

        return DB::transaction(function () use ($policyRequest, $validated, $eventId) {
            // Record provider event
            $event = ProviderEvent::create([
                'policy_request_id' => $policyRequest->id,
                'event_id' => $eventId,
                'event_type' => $validated['event_type'],
                'provider_reference' => $validated['provider_reference'],
                'policy_number' => $validated['policy_number'] ?? null,
                'payload' => $validated,
                'processed' => true,
                'processed_at' => now(),
            ]);

            // If policy is already ISSUED, do NOT overwrite or mutate
            if ($policyRequest->status === PolicyRequest::STATUS_ISSUED) {
                return response()->json([
                    'message' => 'Callback recorded. Policy already confirmed as ISSUED; status unchanged.',
                    'idempotent' => true,
                    'status' => $policyRequest->status,
                    'policy_number' => $policyRequest->policy_number,
                ], 200);
            }

            // Apply event transition
            if ($validated['event_type'] === 'POLICY_ISSUED' && !empty($validated['policy_number'])) {
                $policyRequest->update([
                    'policy_number' => $validated['policy_number'],
                    'provider_reference' => $validated['provider_reference'],
                    'error_details' => null,
                ]);

                $this->stateMachine->transition(
                    $policyRequest,
                    PolicyRequest::STATUS_ISSUED,
                    'callback_policy_issued',
                    $validated,
                    'provider_callback'
                );
            } elseif ($validated['event_type'] === 'POLICY_FAILED') {
                $policyRequest->update([
                    'error_details' => $validated['payload']['error'] ?? 'Policy issuance rejected by insurer callback.',
                ]);

                $this->stateMachine->transition(
                    $policyRequest,
                    PolicyRequest::STATUS_FAILED,
                    'callback_policy_failed',
                    $validated,
                    'provider_callback'
                );
            }

            return response()->json([
                'message' => 'Callback processed successfully.',
                'event_id' => $eventId,
                'status' => $policyRequest->fresh()->status,
                'policy_number' => $policyRequest->fresh()->policy_number,
            ], 200);
        });
    }

    /**
     * GET /api/policy-requests
     * List requests with status filter and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PolicyRequest::query();

        if ($request->has('status') && !empty($request->input('status'))) {
            $query->where('status', strtoupper($request->input('status')));
        }

        if ($request->has('agent_id') && !empty($request->input('agent_id'))) {
            $query->where('agent_id', $request->input('agent_id'));
        }

        $perPage = min(100, max(1, (int) $request->input('per_page', 15)));
        $paginated = $query->latest('id')->paginate($perPage);

        return response()->json([
            'data' => collect($paginated->items())->map(fn($item) => $this->formatPolicyResponse($item)),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ], 200);
    }

    /**
     * Format policy response payload with pricing breakdown and relations.
     */
    protected function formatPolicyResponse(PolicyRequest $policy, bool $includeDetails = false): array
    {
        $response = [
            'id' => $policy->id,
            'uuid' => $policy->uuid,
            'client_request_id' => $policy->client_request_id,
            'agent_id' => $policy->agent_id,
            'customer_name' => $policy->customer_name,
            'date_of_birth' => $policy->date_of_birth?->format('Y-m-d'),
            'destination' => $policy->destination,
            'trip_start' => $policy->trip_start?->format('Y-m-d'),
            'trip_end' => $policy->trip_end?->format('Y-m-d'),
            'plan_code' => $policy->plan_code,
            'sum_insured' => $policy->sum_insured,
            'status' => $policy->status,
            'policy_number' => $policy->policy_number,
            'provider_reference' => $policy->provider_reference,
            'attempt_count' => $policy->attempt_count,
            'error_details' => $policy->error_details,
            'pricing' => [
                'premium' => $policy->premium,
                'travel_days' => $policy->travel_days,
                'base_daily_rate' => $policy->base_daily_rate,
                'age_multiplier' => $policy->age_multiplier,
                'destination_multiplier' => $policy->destination_multiplier,
                'coverage_multiplier' => $policy->coverage_multiplier,
            ],
            'created_at' => $policy->created_at?->toIso8601String(),
            'updated_at' => $policy->updated_at?->toIso8601String(),
        ];

        if ($includeDetails) {
            $response['state_history'] = $policy->stateHistory->map(fn($h) => [
                'from_status' => $h->from_status,
                'to_status' => $h->to_status,
                'trigger' => $h->trigger,
                'actor' => $h->actor,
                'metadata' => $h->metadata,
                'transitioned_at' => $h->transitioned_at?->toIso8601String(),
            ]);

            $response['issuance_attempts'] = $policy->issuanceAttempts->map(fn($a) => [
                'attempt_number' => $a->attempt_number,
                'scenario' => $a->scenario,
                'status' => $a->status,
                'provider_reference' => $a->provider_reference,
                'policy_number' => $a->policy_number,
                'error_message' => $a->error_message,
                'response_time_ms' => $a->response_time_ms,
                'created_at' => $a->created_at?->toIso8601String(),
            ]);

            $response['provider_events'] = $policy->providerEvents->map(fn($e) => [
                'event_id' => $e->event_id,
                'event_type' => $e->event_type,
                'provider_reference' => $e->provider_reference,
                'policy_number' => $e->policy_number,
                'processed' => $e->processed,
                'processed_at' => $e->processed_at?->toIso8601String(),
            ]);
        }

        return $response;
    }
}
