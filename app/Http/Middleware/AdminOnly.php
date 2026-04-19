<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;

class AdminOnly {
    public function handle(Request $request, Closure $next) {
        if (!$request->user() || !$request->user()->is_admin) {
            return response()->json(['detail' => 'Forbidden.'], 403);
        }
        return $next($request);
    }
}
