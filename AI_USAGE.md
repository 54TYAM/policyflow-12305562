# AI Usage & Verification Disclosure

In compliance with Sections 9 & 10 of the **TripperShield / PolicyFlow Engineering Assessment**, this document details the AI tools used in the development of this solution, specific contributions, encountered limitations, and the verification methodologies applied.

---

## 1. Tools Used
- **Claude / Gemini (Antigravity IDE Assistant)**: Used for architecture review, drafting boilerplate models, migrations, and generating comprehensive test scenarios.
- **PHPUnit & Artisan Test Runner**: Used locally to deterministically verify all generated code against actual runtime environments.

---

## 2. Concrete Ways AI Assisted
1. **Pessimistic Locking & Idempotency Design**:
   - Formulating the transaction boundary pattern combining `SELECT ... FOR UPDATE` with unique database indices (`agent_id`, `client_request_id`) and (`event_id`) to ensure zero-duplicate policy creation under concurrent requests.
2. **Formula & Arithmetic Precision**:
   - Assisting in setting up `bcmath` decimal-safe arithmetic operations for the tiered pricing matrix (`base_daily_rate * travel_days * age_multiplier * destination_multiplier * coverage_multiplier`), verifying against the PDF's ₹225.00 calculation example.
3. **Comprehensive Test Suite Generation**:
   - Generating 22 automated unit and feature test cases covering all 4 reliability scenarios, validation edge cases, state regression protection, and the background reconciliation command.

---

## 3. Incomplete / Incorrect Suggestion Encountered & Corrective Action
- **Issue**: During API intake development, an initial suggestion relied on Laravel's Eloquent `$model->wasRecentlyCreated` flag after saving an audit state history transition. Because transitioning the record called `$record->save()` within the same database transaction, Eloquent marked `wasRecentlyCreated` as `false`, causing the controller to respond with HTTP 200 instead of HTTP 201 for fresh creations.
- **Correction**: Identified through automated testing (`PolicyIntakeTest`). Replaced the post-transition model flag inspection with an explicit `$isNewlyCreated` tracking boolean set immediately upon the initial `PolicyRequest::create()` call inside the transaction closure. All tests immediately passed.

---

## 4. Verification & Testing Methodology
- **Automated Regression Suite**: 22 automated tests (128 assertions) run via `php artisan test`, testing unit math, HTTP status codes, validation boundaries, concurrency guards, and idempotent callbacks.
- **Runtime Environment Testing**: Verified on PHP 8.2+ with real MySQL/MariaDB database migrations and constraints (`UNIQUE (agent_id, client_request_id)`, `UNIQUE (event_id)`, `UNIQUE (policy_number)`).
- **Manual Endpoint Verification**: Tested using cURL requests across `POST /api/policy-requests`, `POST /api/policy-requests/{id}/submit`, `GET /api/policy-requests/{id}`, and `POST /api/mock-insurer/callback`.
