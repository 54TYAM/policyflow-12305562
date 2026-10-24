<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyIntakeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Requirement: Valid request produces expected premium and status (201 Created).
     */
    public function test_valid_request_produces_expected_premium_and_status(): void
    {
        $payload = [
            'client_request_id' => 'AGT-2026-0001',
            'agent_id' => 'AGENT-17',
            'customer_name' => 'Sample Customer',
            'date_of_birth' => '1998-04-10',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ];

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('is_duplicate', false)
            ->assertJsonPath('data.status', 'VALIDATED')
            ->assertJsonPath('data.pricing.premium', '225.00')
            ->assertJsonPath('data.pricing.travel_days', 5)
            ->assertJsonPath('data.pricing.base_daily_rate', '20.00');

        $this->assertDatabaseHas('policy_requests', [
            'client_request_id' => 'AGT-2026-0001',
            'agent_id' => 'AGENT-17',
            'status' => 'VALIDATED',
            'premium' => 225.00,
        ]);
    }

    /**
     * Requirement: Invalid travel dates (trip_end before trip_start) are rejected.
     */
    public function test_invalid_travel_dates_are_rejected(): void
    {
        $payload = [
            'client_request_id' => 'AGT-2026-0002',
            'agent_id' => 'AGENT-17',
            'customer_name' => 'John Doe',
            'date_of_birth' => '1990-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-05-15',
            'trip_end' => '2026-05-10', // Invalid: end before start
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ];

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['trip_end']);
    }

    /**
     * Requirement: Unsupported plan is rejected with 422.
     */
    public function test_unsupported_plan_code_is_rejected(): void
    {
        $payload = [
            'client_request_id' => 'AGT-2026-0003',
            'agent_id' => 'AGENT-17',
            'customer_name' => 'Jane Smith',
            'date_of_birth' => '1992-03-12',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-06-01',
            'trip_end' => '2026-06-05',
            'plan_code' => 'PLATINUM', // Unsupported plan
            'sum_insured' => 100000,
        ];

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['plan_code']);
    }

    /**
     * Requirement: Unsupported coverage multiplier amount is rejected with 422.
     */
    public function test_unsupported_sum_insured_is_rejected(): void
    {
        $payload = [
            'client_request_id' => 'AGT-2026-0004',
            'agent_id' => 'AGENT-17',
            'customer_name' => 'Jane Smith',
            'date_of_birth' => '1992-03-12',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-06-01',
            'trip_end' => '2026-06-05',
            'plan_code' => 'BASIC',
            'sum_insured' => 200000, // Unsupported (only 100k, 300k, 500k allowed)
        ];

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sum_insured']);
    }

    /**
     * Requirement: Repeated agent_id + client_request_id does not create another logical request.
     * Safely returns existing with HTTP 200.
     */
    public function test_duplicate_intake_returns_existing_record_with_http_200(): void
    {
        $payload = [
            'client_request_id' => 'AGT-2026-DUPLICATE-01',
            'agent_id' => 'AGENT-99',
            'customer_name' => 'Alice Wonder',
            'date_of_birth' => '1985-05-20',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-08-01',
            'trip_end' => '2026-08-05',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ];

        // First submission -> 201 Created
        $response1 = $this->postJson('/api/policy-requests', $payload);
        $response1->assertStatus(201);
        $firstId = $response1->json('data.id');

        // Second submission with exact same agent_id + client_request_id -> 200 OK
        $response2 = $this->postJson('/api/policy-requests', $payload);
        $response2->assertStatus(200)
            ->assertJsonPath('is_duplicate', true)
            ->assertJsonPath('data.id', $firstId);

        // Verify only 1 record exists in DB
        $count = PolicyRequest::where('agent_id', 'AGENT-99')
            ->where('client_request_id', 'AGT-2026-DUPLICATE-01')
            ->count();
        $this->assertEquals(1, $count);
    }
}
