<?php

use App\Http\Controllers\MockInsurerController;
use App\Http\Controllers\PolicyRequestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| PolicyFlow API Routes
|--------------------------------------------------------------------------
| Fulfills the minimum required API contract for the TripperShield challenge:
| - POST /api/policy-requests             (Create or safely return existing request)
| - GET  /api/policy-requests/{id}         (Retrieve status, premium, references & audit trail)
| - POST /api/policy-requests/{id}/submit  (Start or safely resume issuance)
| - POST /api/mock-insurer/callback       (Receive simulated provider event)
| - GET  /api/policy-requests             (List requests with status filter & pagination)
|
| Mock Insurer Endpoints:
| - POST /api/mock-insurer/issue           (Simulated provider issuance endpoint)
| - GET  /api/mock-insurer/status/{ref}    (Simulated provider reconciliation lookup)
|--------------------------------------------------------------------------
*/

Route::prefix('policy-requests')->group(function () {
    Route::post('/', [PolicyRequestController::class, 'store']);
    Route::get('/', [PolicyRequestController::class, 'index']);
    Route::get('/{id}', [PolicyRequestController::class, 'show']);
    Route::post('/{id}/submit', [PolicyRequestController::class, 'submit']);
});

Route::prefix('mock-insurer')->group(function () {
    Route::post('/callback', [PolicyRequestController::class, 'callback']);
    Route::post('/issue', [MockInsurerController::class, 'issue']);
    Route::get('/status/{reference}', [MockInsurerController::class, 'status']);
});
