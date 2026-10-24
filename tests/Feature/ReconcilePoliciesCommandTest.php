<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReconcilePoliciesCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test the artisan command detects and reconciles stale NEEDS_RECONCILIATION policies.
     */
    public function test_artisan_command_reconciles_stale_policies(): void
    {
        // 1. Create a policy request in NEEDS_RECONCILIATION status
        $intake = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'AGT-CRON-001',
            'agent_id' => 'AGENT-88',
            'customer_name' => 'Jim Hopper',
            'date_of_birth' => '1975-06-15',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-10-01',
            'trip_end' => '2026-10-06',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ]);
        $policyId = $intake->json('data.id');

        // Submit with TIMEOUT_AFTER_ISSUE
        $this->postJson("/api/policy-requests/{$policyId}/submit", [
            'scenario' => 'TIMEOUT_AFTER_ISSUE',
        ]);

        $policy = PolicyRequest::find($policyId);
        $this->assertEquals('NEEDS_RECONCILIATION', $policy->status);

        // 2. Run the artisan reconciliation command
        $this->artisan('policyflow:reconcile', ['--limit' => 10])
            ->expectsOutputToContain('Successfully reconciled')
            ->assertExitCode(0);

        // 3. Confirm policy is now ISSUED
        $reconciledPolicy = PolicyRequest::find($policyId);
        $this->assertEquals('ISSUED', $reconciledPolicy->status);
        $this->assertNotEmpty($reconciledPolicy->policy_number);
    }
}
