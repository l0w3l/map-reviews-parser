<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DisableRegistration
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->is('register'), 404);

        return $next($request);
    }
}
