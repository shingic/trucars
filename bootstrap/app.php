<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Each host has its own sign-in page — send a guest to the one for the
        // portal they were trying to reach.
        $middleware->redirectGuestsTo(fn (\Illuminate\Http\Request $request) => match ($request->getHost()) {
            config('app.admin_domain') => route('admin.login'),
            config('app.staff_domain') => route('dealer.login'),
            default                    => route('buyer.login'),
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
