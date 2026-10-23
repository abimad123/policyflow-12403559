<?php

namespace App\Support;

use App\Models\PolicyRequest;
use Illuminate\Database\Eloquent\Builder;

final class DemoMode
{
    public static function available(): bool
    {
        return app()->environment(['local', 'testing']) || self::public();
    }

    public static function public(): bool
    {
        return app()->environment('production')
            && config('policyflow.public_demo_enabled')
            && ! config('app.debug');
    }

    public static function syntheticPolicies(Builder $query): Builder
    {
        return $query->where('agent_id', 'DEMO-AGENT')
            ->where('customer_name', 'Demo Traveler')
            ->whereRaw("client_request_id ~ '^DEMO-[0-9a-fA-F-]{36}$'");
    }

    public static function syntheticPolicy(PolicyRequest $policy): bool
    {
        return $policy->agent_id === 'DEMO-AGENT'
            && $policy->customer_name === 'Demo Traveler'
            && preg_match('/^DEMO-[0-9a-fA-F-]{36}$/D', $policy->client_request_id) === 1;
    }
}
