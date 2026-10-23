<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IssuanceAttempt extends Model
{
    protected $fillable = [
        'attempt_number', 'retry_key', 'scenario', 'result', 'provider_reference', 'response',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'response' => 'array',
        ];
    }
}
