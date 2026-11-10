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
    ->withMiddleware(function (Middleware $middleware): void {
        // Lets the Vue SPA authenticate via cookies/session instead of API
        // tokens, as long as its origin is listed in SANCTUM_STATEFUL_DOMAINS.
        $middleware->statefulApi();

        // 2026-10-15: blocks a few account-level actions for the shared
        // public demo accounts -- see config/demo.php.
        $middleware->alias([
            'demo.locked' => \App\Http\Middleware\BlockInDemo::class,
            'admin'       => \App\Http\Middleware\EnsureAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
