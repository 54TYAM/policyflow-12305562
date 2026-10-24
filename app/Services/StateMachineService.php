<?php

namespace App\Services;

use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use DomainException;
use Illuminate\Support\Facades\DB;

class StateMachineService
{
    /**
     * Transition a policy request to a new status with validation and audit logging.
     *
     * @param PolicyRequest $policyRequest
     * @param string $newStatus
     * @param string $trigger
     * @param array $metadata
     * @param string $actor
     * @return PolicyRequest
     * @throws DomainException
     */
    public function transition(
        PolicyRequest $policyRequest,
        string $newStatus,
        string $trigger,
        array $metadata = [],
        string $actor = 'system'
    ): PolicyRequest {
        return DB::transaction(function () use ($policyRequest, $newStatus, $trigger, $metadata, $actor) {
            // Lock record for update to prevent concurrent race conditions
            $record = PolicyRequest::where('id', $policyRequest->id)->lockForUpdate()->firstOrFail();

            if ($record->status === $newStatus) {
                // Idempotent state transition (already in requested state)
                return $record;
            }

            if (!$record->canTransitionTo($newStatus)) {
                throw new DomainException(
                    "Invalid state transition: Cannot transition policy request {$record->id} from {$record->status} to {$newStatus}."
                );
            }

            $oldStatus = $record->status;
            $record->status = $newStatus;
            $record->save();

            // Record immutable audit history
            PolicyStateHistory::create([
                'policy_request_id' => $record->id,
                'from_status' => $oldStatus,
                'to_status' => $newStatus,
                'trigger' => $trigger,
                'metadata' => $metadata,
                'actor' => $actor,
                'transitioned_at' => now(),
            ]);

            return $record;
        });
    }
}
