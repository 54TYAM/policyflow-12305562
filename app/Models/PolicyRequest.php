<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PolicyRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'agent_id',
        'client_request_id',
        'customer_name',
        'date_of_birth',
        'destination',
        'trip_start',
        'trip_end',
        'plan_code',
        'sum_insured',
        'premium',
        'travel_days',
        'age_multiplier',
        'destination_multiplier',
        'coverage_multiplier',
        'base_daily_rate',
        'status',
        'policy_number',
        'provider_reference',
        'attempt_count',
        'last_provider_response',
        'error_details',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'trip_start' => 'date',
        'trip_end' => 'date',
        'sum_insured' => 'integer',
        'premium' => 'decimal:2',
        'travel_days' => 'integer',
        'age_multiplier' => 'decimal:2',
        'destination_multiplier' => 'decimal:2',
        'coverage_multiplier' => 'decimal:2',
        'base_daily_rate' => 'decimal:2',
        'attempt_count' => 'integer',
    ];

    /**
     * Valid states for the policy request lifecycle.
     */
    public const STATUS_RECEIVED = 'RECEIVED';
    public const STATUS_VALIDATED = 'VALIDATED';
    public const STATUS_SUBMITTED = 'SUBMITTED';
    public const STATUS_PENDING_CONFIRMATION = 'PENDING_CONFIRMATION';
    public const STATUS_ISSUED = 'ISSUED';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_NEEDS_RECONCILIATION = 'NEEDS_RECONCILIATION';

    /**
     * Valid state transitions: from => [to, to, ...]
     */
    public const VALID_TRANSITIONS = [
        self::STATUS_RECEIVED => [self::STATUS_VALIDATED, self::STATUS_FAILED],
        self::STATUS_VALIDATED => [self::STATUS_SUBMITTED, self::STATUS_FAILED],
        self::STATUS_SUBMITTED => [self::STATUS_PENDING_CONFIRMATION, self::STATUS_ISSUED, self::STATUS_FAILED, self::STATUS_NEEDS_RECONCILIATION],
        self::STATUS_PENDING_CONFIRMATION => [self::STATUS_ISSUED, self::STATUS_FAILED, self::STATUS_NEEDS_RECONCILIATION],
        self::STATUS_FAILED => [self::STATUS_SUBMITTED], // allow retry from FAILED
        self::STATUS_NEEDS_RECONCILIATION => [self::STATUS_ISSUED, self::STATUS_FAILED, self::STATUS_SUBMITTED],
        self::STATUS_ISSUED => [], // terminal state — no transitions allowed
    ];

    /**
     * Boot model — auto-generate UUID on creation.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (PolicyRequest $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Check if a state transition is valid.
     */
    public function canTransitionTo(string $newStatus): bool
    {
        $allowedTransitions = self::VALID_TRANSITIONS[$this->status] ?? [];
        return in_array($newStatus, $allowedTransitions);
    }

    /**
     * Whether this request is in a terminal state.
     */
    public function isTerminal(): bool
    {
        return $this->status === self::STATUS_ISSUED;
    }

    /**
     * Whether this request can be submitted/resubmitted.
     */
    public function canSubmit(): bool
    {
        return in_array($this->status, [
            self::STATUS_VALIDATED,
            self::STATUS_FAILED,
            self::STATUS_NEEDS_RECONCILIATION,
        ]);
    }

    // ─── Relationships ────────────────────────────────────────────────

    public function issuanceAttempts(): HasMany
    {
        return $this->hasMany(IssuanceAttempt::class);
    }

    public function providerEvents(): HasMany
    {
        return $this->hasMany(ProviderEvent::class);
    }

    public function stateHistory(): HasMany
    {
        return $this->hasMany(PolicyStateHistory::class)->orderBy('id');
    }
}
