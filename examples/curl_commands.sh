#!/usr/bin/env bash

# ==============================================================================
# PolicyFlow API - Example cURL Commands
# Run against local server (default: http://127.0.0.1:8000)
# ==============================================================================

BASE_URL="http://127.0.0.1:8000"

echo "=== 1. Intake: Create New Policy Request ==="
curl -s -X POST "${BASE_URL}/api/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "customer_name": "Sample Customer",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }' | jq .

echo -e "\n=== 2. Intake: Duplicate Submission (Safe Idempotent 200) ==="
curl -s -X POST "${BASE_URL}/api/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "customer_name": "Sample Customer",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }' | jq .

echo -e "\n=== 3. Submit for Issuance (SUCCESS Scenario) ==="
curl -s -X POST "${BASE_URL}/api/policy-requests/1/submit" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"scenario": "SUCCESS"}' | jq .

echo -e "\n=== 4. Read Policy Status and Audit Trail ==="
curl -s -X GET "${BASE_URL}/api/policy-requests/1" \
  -H "Accept: application/json" | jq .

echo -e "\n=== 5. Provider Callback (Idempotent Delivery #1) ==="
curl -s -X POST "${BASE_URL}/api/mock-insurer/callback" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "event_id": "EVT-TEST-001",
    "event_type": "POLICY_ISSUED",
    "provider_reference": "INS-REF-SAMPLE",
    "policy_number": "POL-CONFIRMED-888",
    "client_request_id": "AGT-2026-0001"
  }' | jq .

echo -e "\n=== 6. Provider Callback Duplicate (Idempotent Delivery #2) ==="
curl -s -X POST "${BASE_URL}/api/mock-insurer/callback" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "event_id": "EVT-TEST-001",
    "event_type": "POLICY_ISSUED",
    "provider_reference": "INS-REF-SAMPLE",
    "policy_number": "POL-CONFIRMED-888",
    "client_request_id": "AGT-2026-0001"
  }' | jq .

echo -e "\n=== 7. List Policy Requests (Filtered by Status) ==="
curl -s -X GET "${BASE_URL}/api/policy-requests?status=ISSUED&per_page=5" \
  -H "Accept: application/json" | jq .
