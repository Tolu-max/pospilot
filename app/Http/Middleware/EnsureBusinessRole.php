<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBusinessRole
{
    /** @param array<int, string> $roles */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        abort_unless($user !== null && in_array($user->businessRole(), $roles, true), 403);

        return $next($request);
    }
}
