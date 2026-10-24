<?php

namespace App\Services;

use App\Models\IssuanceAttempt;
use App\Models\PolicyRequest;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IssuanceService
{
    public function __construct(
        protected StateMachineService $stateMachine
    ) {}

    /**
     * Submit or safely resume policy issuance.
     *
     * @param PolicyRequest $policyRequest
     * @param string|null $scenario Simulated scenario override (e.g. SUCCESS, TIMEOUT_AFTER_ISSUE)
     * @return PolicyRequest
     */
    public function submit(PolicyRequest $policyRequest, ?string $scenario = null): PolicyRequest
    {
        // 1. Guard against duplicate submission if already ISSUED (Idempotency)
        if ($policyRequest->status === PolicyRequest::STATUS_ISSUED) {
            return $policyRequest;
        }

        // 2. Timeout After Success Guard: Check provider reconciliation before blind retry
        if ($policyRequest->provider_reference || $policyRequest->status === PolicyRequest::STATUS_NEEDS_RECONCILIATION) {
            $existingProviderStatus = $this->queryProviderStatus(
                $policyRequest->provider_reference ?? $policyRequest->client_request_id
            );

            if ($existingProviderStatus && ($existingProviderStatus['status'] ?? '') === 'ISSUED') {
                $policyRequest = $this->reconcileToIssued(
                    $policyRequest,
                    $existingProviderStatus['policy_number'],
                    $existingProviderStatus['provider_reference'],
                    'Reconciled existing policy from provider lookup before resubmission'
                );
                return $policyRequest;
            }
        }

        // 3. Ensure request is in a submittable state
        if (!$policyRequest->canSubmit() && $policyRequest->status !== PolicyRequest::STATUS_SUBMITTED) {
            throw new \DomainException(
                "Cannot submit policy in status: {$policyRequest->status}. Only VALIDATED, FAILED, or NEEDS_RECONCILIATION can be submitted."
            );
        }

        // 4. Transition to SUBMITTED
        if ($policyRequest->status !== PolicyRequest::STATUS_SUBMITTED) {
            $policyRequest = $this->stateMachine->transition(
                $policyRequest,
                PolicyRequest::STATUS_SUBMITTED,
                'user_submission',
                ['scenario' => $scenario],
                'agent'
            );
        }

        $attemptNumber = $policyRequest->attempt_count + 1;
        $policyRequest->increment('attempt_count');

        $startTime = microtime(true);
        $scenarioUsed = $scenario ?: 'SUCCESS';

        $payload = [
            'client_request_id' => $policyRequest->client_request_id,
            'agent_id' => $policyRequest->agent_id,
            'customer_name' => $policyRequest->customer_name,
            'premium' => $policyRequest->premium,
            'scenario' => $scenarioUsed,
        ];

        try {
            // Internal dispatch to mock insurer controller
            $mockController = app(\App\Http\Controllers\MockInsurerController::class);
            $mockRequest = Request::create(
                '/api/mock-insurer/issue',
                'POST',
                $payload,
                [],
                [],
                ['HTTP_X_MOCK_SCENARIO' => $scenarioUsed]
            );

            $response = $mockController->issue($mockRequest);
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);
            $statusCode = $response->getStatusCode();
            $responseData = $response->getData(true) ?: [];

            // Record Issuance Attempt
            $attemptStatus = match (true) {
                $statusCode === 200 => 'SUCCESS',
                $statusCode === 202 => 'PENDING',
                $statusCode === 504 => 'TIMEOUT',
                default => 'FAILED',
            };

            IssuanceAttempt::create([
                'policy_request_id' => $policyRequest->id,
                'attempt_number' => $attemptNumber,
                'scenario' => $scenarioUsed,
                'status' => $attemptStatus,
                'provider_reference' => $responseData['provider_reference'] ?? null,
                'policy_number' => $responseData['policy_number'] ?? null,
                'request_payload' => $payload,
                'response_payload' => $responseData,
                'error_message' => $responseData['message'] ?? null,
                'response_time_ms' => $durationMs,
            ]);

            $policyRequest->update([
                'last_provider_response' => json_encode($responseData),
            ]);

            // Handle response scenarios
            if ($statusCode === 200 && ($responseData['status'] ?? '') === 'SUCCESS') {
                $policyRequest->update([
                    'policy_number' => $responseData['policy_number'],
                    'provider_reference' => $responseData['provider_reference'],
                    'error_details' => null,
                ]);

                return $this->stateMachine->transition(
                    $policyRequest,
                    PolicyRequest::STATUS_ISSUED,
                    'provider_issued_immediately',
                    $responseData,
                    'mock_insurer'
                );
            }

            if ($statusCode === 202 && ($responseData['status'] ?? '') === 'PENDING') {
                $policyRequest->update([
                    'provider_reference' => $responseData['provider_reference'],
                ]);

                return $this->stateMachine->transition(
                    $policyRequest,
                    PolicyRequest::STATUS_PENDING_CONFIRMATION,
                    'provider_pending_callback',
                    $responseData,
                    'mock_insurer'
                );
            }

            if ($statusCode === 504 || ($responseData['status'] ?? '') === 'TIMEOUT') {
                // Timeout after issue scenario: Policy might exist at provider!
                if (!empty($responseData['provider_reference'])) {
                    $policyRequest->update([
                        'provider_reference' => $responseData['provider_reference'],
                    ]);
                }

                $policyRequest->update([
                    'error_details' => 'Upstream provider timed out. Marked for reconciliation.',
                ]);

                return $this->stateMachine->transition(
                    $policyRequest,
                    PolicyRequest::STATUS_NEEDS_RECONCILIATION,
                    'provider_timeout_needs_reconciliation',
                    $responseData,
                    'system'
                );
            }

            // Otherwise, temporary or non-fatal failure
            $policyRequest->update([
                'error_details' => $responseData['message'] ?? 'Provider error encountered.',
            ]);

            return $this->stateMachine->transition(
                $policyRequest,
                PolicyRequest::STATUS_FAILED,
                'provider_error',
                $responseData,
                'mock_insurer'
            );
        } catch (Exception $e) {
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            IssuanceAttempt::create([
                'policy_request_id' => $policyRequest->id,
                'attempt_number' => $attemptNumber,
                'scenario' => $scenarioUsed,
                'status' => 'FAILED',
                'request_payload' => $payload,
                'error_message' => $e->getMessage(),
                'response_time_ms' => $durationMs,
            ]);

            $policyRequest->update([
                'error_details' => $e->getMessage(),
            ]);

            return $this->stateMachine->transition(
                $policyRequest,
                PolicyRequest::STATUS_FAILED,
                'exception_thrown',
                ['error' => $e->getMessage()],
                'system'
            );
        }
    }

    /**
     * Query provider status for reconciliation.
     */
    public function queryProviderStatus(string $reference): ?array
    {
        $mockController = app(\App\Http\Controllers\MockInsurerController::class);
        $request = Request::create("/api/mock-insurer/status/{$reference}", 'GET');
        $response = $mockController->status($request, $reference);

        if ($response->getStatusCode() === 200) {
            return $response->getData(true);
        }

        return null;
    }

    /**
     * Reconcile a policy request into ISSUED state safely.
     */
    public function reconcileToIssued(
        PolicyRequest $policyRequest,
        string $policyNumber,
        string $providerReference,
        string $reason
    ): PolicyRequest {
        return DB::transaction(function () use ($policyRequest, $policyNumber, $providerReference, $reason) {
            $record = PolicyRequest::where('id', $policyRequest->id)->lockForUpdate()->firstOrFail();

            if ($record->status === PolicyRequest::STATUS_ISSUED) {
                return $record;
            }

            $record->update([
                'policy_number' => $policyNumber,
                'provider_reference' => $providerReference,
                'error_details' => null,
            ]);

            return $this->stateMachine->transition(
                $record,
                PolicyRequest::STATUS_ISSUED,
                'reconciliation_success',
                [
                    'reason' => $reason,
                    'policy_number' => $policyNumber,
                    'provider_reference' => $providerReference,
                ],
                'reconciliation_engine'
            );
        });
    }
}
