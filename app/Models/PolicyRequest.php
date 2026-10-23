<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PolicyRequest extends Model
{
    public const STATUS_RECEIVED = 'RECEIVED';

    public const STATUS_VALIDATED = 'VALIDATED';

    public const STATUS_SUBMITTED = 'SUBMITTED';

    public const STATUS_PENDING_CONFIRMATION = 'PENDING_CONFIRMATION';

    public const STATUS_ISSUED = 'ISSUED';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_NEEDS_RECONCILIATION = 'NEEDS_RECONCILIATION';

    protected $fillable = [
        'client_request_id',
        'agent_id',
        'customer_name',
        'date_of_birth',
        'destination',
        'trip_start',
        'trip_end',
        'plan_code',
        'sum_insured',
        'premium_amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date:Y-m-d',
            'trip_start' => 'date:Y-m-d',
            'trip_end' => 'date:Y-m-d',
            'sum_insured' => 'integer',
            'premium_amount' => 'decimal:2',
            'last_provider_response' => 'array',
            'submission_attempt_count' => 'integer',
        ];
    }

    public function stateHistory(): HasMany
    {
        return $this->hasMany(PolicyStateHistory::class)->orderBy('id');
    }

    public function issuanceAttempts(): HasMany
    {
        return $this->hasMany(IssuanceAttempt::class)->orderBy('attempt_number');
    }

    public function mockProviderPolicy(): HasOne
    {
        return $this->hasOne(MockProviderPolicy::class);
    }

    public function providerEvents(): HasMany
    {
        return $this->hasMany(ProviderEvent::class)->orderBy('id');
    }
}
