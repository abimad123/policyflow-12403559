<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PolicyRequest extends Model
{
    public const STATUS_VALIDATED = 'VALIDATED';

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
        ];
    }
}
