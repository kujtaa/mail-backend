<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminOnly::class,
            'approved' => \App\Http\Middleware\ApprovedOnly::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->renderable(function (\Illuminate\Auth\AuthenticationException $e) {
            return response()->json(['detail' => 'Unauthenticated.'], 401);
        });
        $exceptions->renderable(function (\Illuminate\Validation\ValidationException $e) {
            $first = collect($e->errors())->flatten()->first();
            return response()->json(['detail' => $first ?? 'Validation failed.'], 422);
        });
        $exceptions->renderable(function (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json(['detail' => $e->getMessage() ?: 'Error.'], $e->getStatusCode());
        });
        $exceptions->renderable(function (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['detail' => 'Not found.'], 404);
        });
    })->create();
