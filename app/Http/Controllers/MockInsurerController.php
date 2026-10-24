<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MockInsurerController extends Controller
{
    /**
     * Handle policy issuance request to the mock insurer.
     */
    public function issue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_request_id' => 'required|string',
            'agent_id' => 'required|string',
            'customer_name' => 'required|string',
            'premium' => 'required|numeric',
            'scenario' => 'nullable|string',
        ]);

        $scenario = strtoupper($request->header('X-Mock-Scenario', $validated['scenario'] ?? 'SUCCESS'));
        $clientRequestId = $validated['client_request_id'];

        // Check if mock insurer already issued for this client_request_id
        $existing = DB::table('mock_insurer_policies')
            ->where('client_request_id', $clientRequestId)
            ->first();

        if ($existing) {
            return response()->json([
                'status' => $existing->status === 'ISSUED' ? 'SUCCESS' : $existing->status,
                'provider_reference' => $existing->provider_reference,
                'policy_number' => $existing->policy_number,
                'message' => 'Returning previously issued policy from provider records',
            ], 200);
        }

        $providerReference = 'INS-' . strtoupper(Str::random(10));
        $policyNumber = 'POL-' . date('Ymd') . '-' . strtoupper(Str::random(6));

        switch ($scenario) {
            case 'TEMPORARY_FAILURE':
                return response()->json([
                    'status' => 'FAILURE',
                    'error_code' => 'PROVIDER_UNAVAILABLE',
                    'message' => 'Simulated temporary connection outage. Please retry.',
                    'retriable' => true,
                ], 503);

            case 'TIMEOUT_AFTER_ISSUE':
                // Provider issues the policy internally, but network fails before response arrives
                DB::table('mock_insurer_policies')->insert([
                    'provider_reference' => $providerReference,
                    'client_request_id' => $clientRequestId,
                    'policy_number' => $policyNumber,
                    'status' => 'ISSUED',
                    'premium' => $validated['premium'],
                    'payload' => json_encode($validated),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return response()->json([
                    'status' => 'TIMEOUT',
                    'error_code' => 'REQUEST_TIMEOUT',
                    'message' => 'Simulated upstream gateway timeout after policy was recorded',
                    'provider_reference' => $providerReference,
                ], 504);

            case 'DELAYED_CALLBACK':
                // Policy is queued at provider, will issue via callback
                DB::table('mock_insurer_policies')->insert([
                    'provider_reference' => $providerReference,
                    'client_request_id' => $clientRequestId,
                    'policy_number' => $policyNumber,
                    'status' => 'PENDING',
                    'premium' => $validated['premium'],
                    'payload' => json_encode($validated),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return response()->json([
                    'status' => 'PENDING',
                    'provider_reference' => $providerReference,
                    'message' => 'Policy issuance request queued. Awaiting asynchronous confirmation.',
                ], 202);

            case 'SUCCESS':
            default:
                DB::table('mock_insurer_policies')->insert([
                    'provider_reference' => $providerReference,
                    'client_request_id' => $clientRequestId,
                    'policy_number' => $policyNumber,
                    'status' => 'ISSUED',
                    'premium' => $validated['premium'],
                    'payload' => json_encode($validated),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return response()->json([
                    'status' => 'SUCCESS',
                    'provider_reference' => $providerReference,
                    'policy_number' => $policyNumber,
                    'message' => 'Policy issued successfully',
                ], 200);
        }
    }

    /**
     * Provider status lookup to safely resolve timeouts and reconcile state.
     */
    public function status(Request $request, string $reference): JsonResponse
    {
        $policy = DB::table('mock_insurer_policies')
            ->where('provider_reference', $reference)
            ->orWhere('client_request_id', $reference)
            ->first();

        if (!$policy) {
            return response()->json([
                'found' => false,
                'message' => "No policy record found for identifier: {$reference}",
            ], 404);
        }

        return response()->json([
            'found' => true,
            'provider_reference' => $policy->provider_reference,
            'client_request_id' => $policy->client_request_id,
            'policy_number' => $policy->policy_number,
            'status' => $policy->status,
            'premium' => $policy->premium,
        ], 200);
    }
}
