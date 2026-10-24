<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssuanceAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'policy_request_id',
        'attempt_number',
        'scenario',
        'status',
        'provider_reference',
        'policy_number',
        'request_payload',
        'response_payload',
        'error_message',
        'response_time_ms',
    ];

    protected $casts = [
        'attempt_number' => 'integer',
        'response_time_ms' => 'integer',
        'request_payload' => 'array',
        'response_payload' => 'array',
    ];

    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class);
    }
}
