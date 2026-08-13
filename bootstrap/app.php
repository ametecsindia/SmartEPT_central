<?php

// CROSS-APP DB POISONING FIX (Ejaz, 13-Aug-2026): Central and the product
// console share one Apache/PHP-FPM worker pool (Laragon local AND the live
// VPS). Laravel's env loader uses putenv(), which PERSISTS in a worker across
// requests — so a worker that just served the OTHER app keeps its DB_DATABASE
// and, because dotenv loading is immutable, this app then silently runs on the
// WRONG DATABASE (proven 13-Aug: Central's /api/validate queried `smartept`
// and 500'd "licences doesn't exist"; same mechanism as the July agent/
// screenshot wrong-DB incident). Disabling putenv makes every request read
// .env fresh — full isolation between the two apps. Same line in both repos.
\Illuminate\Support\Env::disablePutenv();

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
