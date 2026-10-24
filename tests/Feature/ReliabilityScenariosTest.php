<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReliabilityScenariosTest extends TestCase
{
    use RefreshDatabase;

    /**
     * RELIABILITY CASE 1: Duplicate Intake
     * The same client_request_id + agent_id is submitted repeatedly.
     * Must not create another logical request; safely returns existing record with HTTP 200.
     */
    public function test_case_1_duplicate_intake_prevents_duplicate_records(): void
    {
        $payload = [
            'client_request_id' => 'AGT-RELIABILITY-001',
            'agent_id' => 'AGENT-101',
            'customer_name' => 'Michael Scott',
            'date_of_birth' => '1965-03-15',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-12-01',
            'trip_end' => '2026-12-10',
            'plan_code' => 'PREMIUM',
            'sum_insured' => 500000,
        ];

        // 1st request -> 201 Created
        $response1 = $this->postJson('/api/policy-requests', $payload);
        $response1->assertStatus(201);
        $originalId = $response1->json('data.id');

        // 2nd request (duplicate intake) -> 200 OK with same ID
        $response2 = $this->postJson('/api/policy-requests', $payload);
        $response2->assertStatus(200)
            ->assertJsonPath('is_duplicate', true)
            ->assertJsonPath('data.id', $originalId);

        // 3rd request (duplicate intake) -> 200 OK
        $response3 = $this->postJson('/api/policy-requests', $payload);
        $response3->assertStatus(200)
            ->assertJsonPath('is_duplicate', true)
            ->assertJsonPath('data.id', $originalId);

        // Assert exactly one record in database
        $this->assertEquals(
            1,
            PolicyRequest::where('client_request_id', 'AGT-RELIABILITY-001')->count()
        );
    }

    /**
     * RELIABILITY CASE 2: Timeout After Success & Reconciliation
     * The insurer issued the policy internally, but client call timed out (504).
     * The system transitions to NEEDS_RECONCILIATION.
     * When submitted again or reconciled, it queries provider status first and transitions to
     * ISSUED without blindly generating a duplicate policy.
     */
    public function test_case_2_timeout_after_success_reconciles_safely_without_reissuing(): void
    {
        // 1. Intake policy request
        $intake = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'AGT-TIMEOUT-001',
            'agent_id' => 'AGENT-102',
            'customer_name' => 'Dwight Schrute',
            'date_of_birth' => '1970-01-20',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-10-01',
            'trip_end' => '2026-10-05',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ]);
        $policyId = $intake->json('data.id');

        // 2. Submit with TIMEOUT_AFTER_ISSUE scenario
        $submit = $this->postJson("/api/policy-requests/{$policyId}/submit", [
            'scenario' => 'TIMEOUT_AFTER_ISSUE',
        ]);

        $submit->assertStatus(200)
            ->assertJsonPath('data.status', 'NEEDS_RECONCILIATION');

        $policyAfterTimeout = PolicyRequest::find($policyId);
        $this->assertEquals('NEEDS_RECONCILIATION', $policyAfterTimeout->status);
        $this->assertNotEmpty($policyAfterTimeout->provider_reference);

        // Verify the mock insurer DID record the policy internally
        $mockPolicy = DB::table('mock_insurer_policies')
            ->where('client_request_id', 'AGT-TIMEOUT-001')
            ->first();
        $this->assertNotNull($mockPolicy);
        $this->assertEquals('ISSUED', $mockPolicy->status);

        // 3. Retry / Resume submission after timeout:
        // System must check provider status BEFORE blind reissue!
        $resubmit = $this->postJson("/api/policy-requests/{$policyId}/submit");

        $resubmit->assertStatus(200)
            ->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.policy_number', $mockPolicy->policy_number);

        // Confirm policy request is now ISSUED with exactly the provider's policy number
        $policyFinal = PolicyRequest::find($policyId);
        $this->assertEquals('ISSUED', $policyFinal->status);
        $this->assertEquals($mockPolicy->policy_number, $policyFinal->policy_number);

        // Verify only 1 policy was created at the provider for this client_request_id
        $this->assertEquals(
            1,
            DB::table('mock_insurer_policies')->where('client_request_id', 'AGT-TIMEOUT-001')->count()
        );
    }

    /**
     * RELIABILITY CASE 3: Duplicate Callback Handling
     * The same provider event (callback) is delivered multiple times.
     * Processing must be idempotent (applied only once).
     */
    public function test_case_3_duplicate_callback_processed_idempotently(): void
    {
        // 1. Intake policy request
        $intake = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'AGT-CALLBACK-001',
            'agent_id' => 'AGENT-103',
            'customer_name' => 'Jim Halpert',
            'date_of_birth' => '1978-10-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-11-01',
            'trip_end' => '2026-11-04',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ]);
        $policyId = $intake->json('data.id');

        // 2. Submit with DELAYED_CALLBACK scenario (status will become PENDING_CONFIRMATION)
        $submit = $this->postJson("/api/policy-requests/{$policyId}/submit", [
            'scenario' => 'DELAYED_CALLBACK',
        ]);

        $submit->assertStatus(200)
            ->assertJsonPath('data.status', 'PENDING_CONFIRMATION');

        $providerRef = $submit->json('data.provider_reference');
        $this->assertNotEmpty($providerRef);

        // 3. Callback arrives (Delivery #1)
        $callbackPayload = [
            'event_id' => 'EVT-998877-01',
            'event_type' => 'POLICY_ISSUED',
            'provider_reference' => $providerRef,
            'policy_number' => 'POL-CONFIRMED-999',
            'client_request_id' => 'AGT-CALLBACK-001',
        ];

        $cbResponse1 = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $cbResponse1->assertStatus(200)
            ->assertJsonPath('status', 'ISSUED')
            ->assertJsonPath('policy_number', 'POL-CONFIRMED-999');

        $policy = PolicyRequest::find($policyId);
        $this->assertEquals('ISSUED', $policy->status);
        $this->assertEquals('POL-CONFIRMED-999', $policy->policy_number);

        // 4. Callback duplicate arrives (Delivery #2 - identical event_id)
        $cbResponse2 = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $cbResponse2->assertStatus(200)
            ->assertJsonPath('idempotent', true);

        // 5. Callback duplicate arrives (Delivery #3 - identical event_id)
        $cbResponse3 = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $cbResponse3->assertStatus(200)
            ->assertJsonPath('idempotent', true);

        // Assert exactly one provider event was stored for this event_id
        $this->assertEquals(1, ProviderEvent::where('event_id', 'EVT-998877-01')->count());

        // Policy status remains ISSUED
        $this->assertEquals('ISSUED', PolicyRequest::find($policyId)->status);
    }

    /**
     * RELIABILITY CASE 4: Retry After Temporary Failure
     * Insurer returns retriable failure. System transitions to FAILED.
     * Retrying does not produce duplicate policies or lose original accepted premium snapshot.
     */
    public function test_case_4_retry_after_temporary_failure_preserves_premium_and_issues_safely(): void
    {
        // 1. Intake policy request
        $intake = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'AGT-RETRY-001',
            'agent_id' => 'AGENT-104',
            'customer_name' => 'Pam Beesly',
            'date_of_birth' => '1980-03-25',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-20',
            'trip_end' => '2026-11-25',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ]);
        $policyId = $intake->json('data.id');
        $originalPremium = $intake->json('data.pricing.premium');

        // 2. Submit with TEMPORARY_FAILURE
        $submit1 = $this->postJson("/api/policy-requests/{$policyId}/submit", [
            'scenario' => 'TEMPORARY_FAILURE',
        ]);

        $submit1->assertStatus(200)
            ->assertJsonPath('data.status', 'FAILED');

        $policyAfterFail = PolicyRequest::find($policyId);
        $this->assertEquals('FAILED', $policyAfterFail->status);
        $this->assertEquals(1, $policyAfterFail->attempt_count);
        $this->assertEquals($originalPremium, $policyAfterFail->premium);

        // 3. Retry with SUCCESS scenario
        $submit2 = $this->postJson("/api/policy-requests/{$policyId}/submit", [
            'scenario' => 'SUCCESS',
        ]);

        $submit2->assertStatus(200)
            ->assertJsonPath('data.status', 'ISSUED');

        $policyFinal = PolicyRequest::find($policyId);
        $this->assertEquals('ISSUED', $policyFinal->status);
        $this->assertEquals(2, $policyFinal->attempt_count);
        // Premium was NOT altered or re-priced
        $this->assertEquals($originalPremium, $policyFinal->premium);
        $this->assertNotEmpty($policyFinal->policy_number);
    }

    /**
     * REQUIREMENT: Repeated callback or resubmission CANNOT overwrite or regress confirmed ISSUED state.
     */
    public function test_cannot_overwrite_or_regress_confirmed_issued_state(): void
    {
        // 1. Create and issue policy
        $intake = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'AGT-TERMINAL-001',
            'agent_id' => 'AGENT-105',
            'customer_name' => 'Stanley Hudson',
            'date_of_birth' => '1958-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-12-10',
            'trip_end' => '2026-12-15',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ]);
        $policyId = $intake->json('data.id');

        $this->postJson("/api/policy-requests/{$policyId}/submit", ['scenario' => 'SUCCESS']);

        $policy = PolicyRequest::find($policyId);
        $originalPolicyNumber = $policy->policy_number;
        $this->assertEquals('ISSUED', $policy->status);

        // 2. Resubmission must be a safe no-op (idempotent, status stays ISSUED)
        $resubmit = $this->postJson("/api/policy-requests/{$policyId}/submit", ['scenario' => 'SUCCESS']);
        $resubmit->assertStatus(200)
            ->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.policy_number', $originalPolicyNumber);

        // 3. Late callback attempting to report FAILURE must NOT overwrite ISSUED status
        $lateFailCallback = $this->postJson('/api/mock-insurer/callback', [
            'event_id' => 'EVT-LATE-FAIL-001',
            'event_type' => 'POLICY_FAILED',
            'provider_reference' => $policy->provider_reference,
            'client_request_id' => 'AGT-TERMINAL-001',
            'payload' => ['error' => 'Late cancellation'],
        ]);

        $lateFailCallback->assertStatus(200)
            ->assertJsonPath('idempotent', true)
            ->assertJsonPath('status', 'ISSUED');

        $this->assertEquals('ISSUED', PolicyRequest::find($policyId)->status);
        $this->assertEquals($originalPolicyNumber, PolicyRequest::find($policyId)->policy_number);
    }
}
