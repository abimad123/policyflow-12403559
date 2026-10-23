<?php

namespace App\Http\Middleware;

use App\Support\DemoMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLocalDemo
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(DemoMode::available(), 404);

        return $next($request);
    }
}
