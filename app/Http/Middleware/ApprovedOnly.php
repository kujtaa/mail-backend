<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;

class ApprovedOnly {
    public function handle(Request $request, Closure $next) {
        if (!$request->user() || !$request->user()->is_approved) {
            return response()->json(['detail' => 'Account pending approval.'], 403);
        }
        return $next($request);
    }
}
