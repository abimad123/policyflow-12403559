<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PolicyStateHistory extends Model
{
    public $timestamps = false;

    protected $fillable = ['previous_state', 'new_state', 'context', 'occurred_at'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
