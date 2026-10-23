<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('policyflow:diagnose', function () {
    $checks = [
        'production_environment' => app()->environment('production'),
        'debug_disabled' => ! config('app.debug'),
        'public_demo_enabled' => (bool) config('policyflow.public_demo_enabled'),
        'postgresql_selected' => config('database.default') === 'pgsql',
        'stderr_logging' => config('logging.default') === 'stderr',
    ];

    try {
        DB::select('SELECT 1');
        $checks['database_connected'] = true;
    } catch (Throwable $exception) {
        $checks['database_connected'] = false;
    }

    if ($checks['database_connected']) {
        try {
            $checks['intake_schema_ready'] = Schema::hasColumns('policy_requests', [
                'id', 'agent_id', 'client_request_id', 'premium_amount', 'status',
                'provider_reference', 'submission_attempt_count', 'policy_number',
            ]) && Schema::hasColumns('policy_state_histories', [
                'policy_request_id', 'previous_state', 'new_state', 'context', 'occurred_at',
            ]);
        } catch (Throwable $exception) {
            $checks['intake_schema_ready'] = false;
        }
    } else {
        $checks['intake_schema_ready'] = false;
    }

    foreach ($checks as $name => $passed) {
        $this->line($name.': '.($passed ? 'yes' : 'no'));
    }

    return in_array(false, $checks, true) ? 1 : 0;
})->purpose('Check demo configuration and read-only database readiness without printing secrets');
