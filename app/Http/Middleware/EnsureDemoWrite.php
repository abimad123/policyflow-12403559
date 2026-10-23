<?php

namespace App\Http\Middleware;

use App\Models\PolicyRequest;
use App\Support\DemoMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDemoWrite
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment(['local', 'testing'])) {
            return $next($request);
        }

        $expected = config('policyflow.demo_write_token');
        $provided = $request->header('X-PolicyFlow-Demo-Token');

        abort_unless(
            DemoMode::public()
                && is_string($expected)
                && strlen($expected) >= 32
                && is_string($provided)
                && hash_equals($expected, $provided),
            403,
            'Demo write access is unavailable or not authorized.',
        );

        $id = $request->route('id');
        if ($id !== null) {
            $policy = PolicyRequest::query()->findOrFail($id);
            abort_unless(DemoMode::syntheticPolicy($policy), 404);
        }

        return $next($request);
    }
}
