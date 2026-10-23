<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderEvent extends Model
{
    protected $fillable = ['event_id', 'provider_reference', 'event_type', 'policy_number', 'applied', 'payload'];

    protected function casts(): array
    {
        return ['applied' => 'boolean', 'payload' => 'array'];
    }
}
