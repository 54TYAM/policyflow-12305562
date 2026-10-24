# PolicyFlow — Fault-Tolerant Insurance Issuance & Reconciliation Engine

> **TripperShield / Campus Engineering Challenge**  
> Built with **PHP 8.2+**, **Laravel 12**, **MySQL 8+**, and **PHPUnit**.

---

## 1. Architecture Overview

PolicyFlow is designed to sit between upstream travel agents and an unreliable external insurance provider. It guarantees **idempotency**, **auditability**, and **safe recovery from network failures**.

```
                   ┌─────────────────────────────────────────┐
                   │               Client / Agent            │
                   └────────────────────┬────────────────────┘
                                        │
           POST /api/policy-requests    │   POST /api/policy-requests/{id}/submit
                                        ▼
┌──────────────────────────────────────────────────────────────────────────────┐
│ PolicyFlow Engine                                                            │
│                                                                              │
│  ┌────────────────────────┐        ┌──────────────────────────────────────┐  │
│  │ PremiumCalculator      │        │ State Machine & Audit History        │  │
│  │ (bcmath decimal-safe)  │        │ (Strict valid transition DAG)        │  │
│  └────────────────────────┘        └──────────────────────────────────────┘  │
│                                                       │                      │
│                                                       ▼                      │
│                                         ┌───────────────────────────┐        │
│                                         │ Issuance Service          │        │
│                                         │ - Provider status checks  │        │
│                                         │ - Concurrency lock        │        │
│                                         └─────────────┬─────────────┘        │
└───────────────────────────────────────────────────────┼──────────────────────┘
                                                        │
                      ┌─────────────────────────────────┴─────────────┐
                      │ HTTP / Webhook                                │
                      ▼                                               ▼
         ┌─────────────────────────┐                     ┌─────────────────────────┐
         │ Mock Insurer API        │                     │ Reconciliation Engine   │
         │ - SUCCESS               │                     │ (Artisan command &      │
         │ - TIMEOUT_AFTER_ISSUE   │                     │  pre-submission check)  │
         │ - TEMPORARY_FAILURE     │                     └─────────────────────────┘
         │ - DELAYED_CALLBACK      │
         └─────────────────────────┘
```

### State Machine Lifecycle
The policy request lifecycle follows a strict transition graph:
```
[RECEIVED] ──> [VALIDATED] ──> [SUBMITTED] ──┬──> [ISSUED] (Terminal)
                                             ├──> [PENDING_CONFIRMATION] ──> [ISSUED] / [FAILED]
                                             ├──> [NEEDS_RECONCILIATION] ──> [ISSUED] / [FAILED]
                                             └──> [FAILED] ──> [SUBMITTED] (Safe Retry)
```
- **Terminal State Protection**: Once marked `ISSUED`, regressions (such as `ISSUED -> SUBMITTED` or late failure callbacks) are strictly rejected.

---

## 2. Duplicate Prevention & Reliability Strategy

### A. Duplicate Intake (Reliability Case 1)
- **Problem**: The same `client_request_id` is submitted repeatedly by an agent, either intentionally or via near-simultaneous retries.
- **Solution**:
  1. A composite unique database constraint on `(agent_id, client_request_id)` enforces uniqueness at the storage engine level.
  2. The intake controller uses a pessimistic `lockForUpdate()` check within a transaction.
  3. Subsequent submissions return HTTP `200 OK` with the existing policy request, while the initial submission returns HTTP `201 Created`.

### B. Timeout After Success & Safe Reconciliation (Reliability Case 2)
- **Problem**: The upstream insurer issues a policy, but network drops before PolicyFlow receives the response (HTTP 504). Blindly resubmitting would issue duplicate policies.
- **Solution**:
  1. PolicyFlow marks the request as `NEEDS_RECONCILIATION` and preserves the provider reference.
  2. Prior to any resubmission attempt, PolicyFlow automatically queries the provider status lookup endpoint (`/api/mock-insurer/status/{reference}`).
  3. If the provider confirms the policy was recorded, PolicyFlow immediately reconciles to `ISSUED` without re-invoking the provider's issuance endpoint.
  4. A background command `php artisan policyflow:reconcile` automatically detects and reconciles stale pending policies.

### C. Duplicate Callback Handling (Reliability Case 3)
- **Problem**: Insurer webhooks deliver the same event multiple times.
- **Solution**:
  1. Every provider event is tracked in `provider_events` with a unique index on `event_id`.
  2. If an `event_id` is re-received, it is acknowledged with HTTP `200 OK` (`idempotent: true`) without re-executing state transitions.
  3. Callbacks cannot mutate or regress a confirmed `ISSUED` policy.

### D. Retry After Temporary Failure (Reliability Case 4)
- **Problem**: Network outages cause retriable errors without losing the agreed price.
- **Solution**:
  1. The initial price calculation snapshot is persisted immutably upon validation.
  2. Retries increment `attempt_count` and log every request/response payload in `issuance_attempts`, retaining the original calculated premium.

---

## 3. Database Schema

- **`policy_requests`**: Core entity with customer details, trip dates, plan, immutable premium snapshot, and status. Unique index: `(agent_id, client_request_id)`.
- **`issuance_attempts`**: Audit of every submission attempt, scenario, status, latency in milliseconds, and payloads.
- **`provider_events`**: Stores external webhook payloads. Unique index: `event_id`.
- **`policy_state_history`**: Immutable timeline of every status transition (`from_status`, `to_status`, `trigger`, `actor`, `transitioned_at`).
- **`mock_insurer_policies`**: Mock provider's internal state table for lookup and reconciliation.

---

## 4. Setup Instructions

### Prerequisites
- **PHP 8.2+** with `bcmath`, `pdo_mysql`, and `zip` extensions enabled
- **Composer 2.x**
- **MySQL 8.0+** or **MariaDB 10.4+**

### Step-by-Step Installation

1. **Clone the repository**:
   ```bash
   git clone <repo-url> policyflow
   cd policyflow
   ```

2. **Install Composer dependencies**:
   ```bash
   composer install
   ```

3. **Configure Environment**:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Database Configuration**:
   Ensure MySQL is running and create the database:
   ```sql
   CREATE DATABASE policyflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
   Verify `.env` has:
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=policyflow
   DB_USERNAME=root
   DB_PASSWORD=
   ```

5. **Run Migrations**:
   ```bash
   php artisan migrate
   ```

6. **Start Local Development Server**:
   ```bash
   php artisan serve
   ```
   Server will be available at: `http://127.0.0.1:8000`

---

## 5. Running Automated Tests

Run the full automated test suite (22 tests, 128 assertions):

```bash
php artisan test
```

Or run specific test suites:
```bash
# Unit tests for premium calculations
php artisan test --filter=PremiumCalculatorServiceTest

# Feature tests for policy intake & validation
php artisan test --filter=PolicyIntakeTest

# Feature tests for reliability scenarios
php artisan test --filter=ReliabilityScenariosTest

# Feature tests for reconciliation command
php artisan test --filter=ReconcilePoliciesCommandTest
```

---

## 6. API Reference & Examples

### 1. Create Policy Request (Intake)
- **POST** `/api/policy-requests`
```bash
curl -X POST http://127.0.0.1:8000/api/policy-requests \
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
  }'
```
**Response (201 Created)**:
```json
{
  "message": "Policy request successfully created and validated.",
  "is_duplicate": false,
  "data": {
    "id": 1,
    "uuid": "4c9e4210-9ec8-45ee-997a-9a997d91dfc1",
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "status": "VALIDATED",
    "pricing": {
      "premium": "225.00",
      "travel_days": 5,
      "base_daily_rate": "20.00",
      "age_multiplier": "1.00",
      "destination_multiplier": "1.80",
      "coverage_multiplier": "1.25"
    }
  }
}
```

### 2. Submit for Issuance
- **POST** `/api/policy-requests/{id}/submit`
```bash
curl -X POST http://127.0.0.1:8000/api/policy-requests/1/submit \
  -H "Content-Type: application/json" \
  -d '{"scenario": "SUCCESS"}'
```

### 3. Retrieve Status & Audit Trail
- **GET** `/api/policy-requests/{id}`
```bash
curl -X GET http://127.0.0.1:8000/api/policy-requests/1 \
  -H "Accept: application/json"
```

### 4. Provider Callback (Idempotent)
- **POST** `/api/mock-insurer/callback`
```bash
curl -X POST http://127.0.0.1:8000/api/mock-insurer/callback \
  -H "Content-Type: application/json" \
  -d '{
    "event_id": "EVT-998877",
    "event_type": "POLICY_ISSUED",
    "provider_reference": "INS-SAMPLE-01",
    "policy_number": "POL-20261024-ABC123"
  }'
```

### 5. Run Reconciliation Background Command
```bash
php artisan policyflow:reconcile --limit=50
```

---

## 7. Known Assumptions & Limitations

1. **Mock Insurer Scope**: External provider interaction is simulated using internal controllers and a persisted `mock_insurer_policies` table to allow deterministic scenario-based testing without external network dependencies.
2. **Synchronous Issuance with Queue Support**: Submission is handled synchronously in the demonstration endpoint, while database structure (`issuance_attempts`, `provider_events`) is fully decoupled and ready for Laravel Queue workers (`QUEUE_CONNECTION=database`).
3. **Currency Precision**: All monetary values are handled using `bcmath` string math rounded to 2 decimal places to avoid IEEE 754 floating-point inaccuracies.
