<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MockProviderPolicy extends Model
{
    protected $fillable = ['provider_reference', 'policy_number', 'status'];
}
