<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyStateHistory extends Model
{
    use HasFactory;

    protected $table = 'policy_state_history';

    protected $fillable = [
        'policy_request_id',
        'from_status',
        'to_status',
        'trigger',
        'metadata',
        'actor',
        'transitioned_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'transitioned_at' => 'datetime',
    ];

    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class);
    }
}
