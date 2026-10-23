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

        abort_unless(DemoMode::public(), 403, 'Public demo writes are disabled.');

        $body = $request->getContent();
        abort_if(strlen($body) > 8192, 413, 'Demo request is too large.');
        if ($body !== '') {
            abort_unless($request->isJson(), 415, 'JSON is required for demo requests.');
            json_decode($body);
            abort_if(json_last_error() !== JSON_ERROR_NONE, 400, 'Malformed JSON request.');
        }

        $id = $request->route('id');
        if ($id !== null) {
            $policy = PolicyRequest::query()->findOrFail($id);
            abort_unless(DemoMode::syntheticPolicy($policy), 404);
        }

        return $next($request);
    }
}
