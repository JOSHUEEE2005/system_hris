<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $permissions = explode('|', $permission);
        $allowed = collect($permissions)->contains(
            fn (string $required) => $request->user()?->hasPermission($required),
        );
        abort_unless($allowed, 403);

        return $next($request);
    }
}
