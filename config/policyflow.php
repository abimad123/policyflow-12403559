<?php

return [
    'public_demo_enabled' => (bool) env('POLICYFLOW_PUBLIC_DEMO', false),
    'demo_write_token' => env('POLICYFLOW_DEMO_WRITE_TOKEN'),
];
