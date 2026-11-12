<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Deployed as a real subfolder (api.example.com/api -> this app's
        // public/), the "/api" segment is already consumed as the front
        // controller's base path, so Laravel must not add its own "api"
        // prefix on top of that or every route would need an extra "/api"
        // (mobile app calling .../api/api/v1/... instead of .../api/v1/...).
        apiPrefix: '',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            if ($request->is('v1/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                    'error' => ['code' => 'UNAUTHENTICATED']
                ], 401);
            }
        });
    })->create();
