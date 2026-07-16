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
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'admin.auth' => \App\Http\Middleware\AdminAuth::class,
            'admin.role' => \App\Http\Middleware\AdminRole::class,
            'client.auth' => \App\Http\Middleware\ClientAuth::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'webhooks/razorpay',
            'webhooks/stripe',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
