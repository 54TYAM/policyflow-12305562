<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IssuanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Requirement: Successful provider response produces exactly one policy number.
     */
    public function test_successful_provider_response_produces_exactly_one_policy_number(): void
    {
        // 1. Create a validated policy request
        $intakeResponse = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'AGT-REQ-1001',
            'agent_id' => 'AGENT-01',
            'customer_name' => 'John Doe',
            'date_of_birth' => '1995-02-14',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-09-01',
            'trip_end' => '2026-09-03',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ]);
        $intakeResponse->assertStatus(201);
        $policyId = $intakeResponse->json('data.id');

        // 2. Submit for issuance with SUCCESS scenario
        $submitResponse = $this->postJson("/api/policy-requests/{$policyId}/submit", [
            'scenario' => 'SUCCESS',
        ]);

        $submitResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'ISSUED');

        $policyNumber = $submitResponse->json('data.policy_number');
        $this->assertNotEmpty($policyNumber);
        $this->assertStringStartsWith('POL-', $policyNumber);

        // Verify policy request updated in DB
        $policy = PolicyRequest::find($policyId);
        $this->assertEquals('ISSUED', $policy->status);
        $this->assertEquals($policyNumber, $policy->policy_number);
        $this->assertEquals(1, $policy->attempt_count);

        // Verify state history audit trail recorded transitions:
        // RECEIVED -> VALIDATED -> SUBMITTED -> ISSUED
        $this->assertDatabaseHas('policy_state_history', [
            'policy_request_id' => $policyId,
            'to_status' => 'ISSUED',
        ]);
    }

    /**
     * Requirement: Expose read endpoint to retrieve state and audit trail.
     */
    public function test_read_endpoint_returns_status_premium_and_audit_trail(): void
    {
        $intakeResponse = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'AGT-REQ-1002',
            'agent_id' => 'AGENT-01',
            'customer_name' => 'Audit Tester',
            'date_of_birth' => '1990-01-01',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-10-01',
            'trip_end' => '2026-10-05',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ]);
        $policyId = $intakeResponse->json('data.id');

        $this->postJson("/api/policy-requests/{$policyId}/submit", [
            'scenario' => 'SUCCESS',
        ]);

        // GET /api/policy-requests/{id}
        $showResponse = $this->getJson("/api/policy-requests/{$policyId}");

        $showResponse->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'uuid',
                    'client_request_id',
                    'status',
                    'policy_number',
                    'provider_reference',
                    'pricing' => [
                        'premium',
                        'travel_days',
                    ],
                    'state_history',
                    'issuance_attempts',
                ]
            ]);

        $history = $showResponse->json('data.state_history');
        $this->assertGreaterThanOrEqual(3, count($history));
    }

    /**
     * Requirement: List requests with status filter and pagination.
     */
    public function test_index_endpoint_filters_by_status(): void
    {
        // Create 2 records
        $this->postJson('/api/policy-requests', [
            'client_request_id' => 'AGT-FILTER-1',
            'agent_id' => 'AGENT-01',
            'customer_name' => 'User 1',
            'date_of_birth' => '1990-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-10-01',
            'trip_end' => '2026-10-02',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ]);

        $res2 = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'AGT-FILTER-2',
            'agent_id' => 'AGENT-01',
            'customer_name' => 'User 2',
            'date_of_birth' => '1990-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-10-01',
            'trip_end' => '2026-10-02',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ]);
        $id2 = $res2->json('data.id');
        $this->postJson("/api/policy-requests/{$id2}/submit", ['scenario' => 'SUCCESS']);

        // Filter status = VALIDATED -> should find 1
        $responseValidated = $this->getJson('/api/policy-requests?status=VALIDATED');
        $responseValidated->assertStatus(200)
            ->assertJsonPath('meta.total', 1);

        // Filter status = ISSUED -> should find 1
        $responseIssued = $this->getJson('/api/policy-requests?status=ISSUED');
        $responseIssued->assertStatus(200)
            ->assertJsonPath('meta.total', 1);
    }
}
