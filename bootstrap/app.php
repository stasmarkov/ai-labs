<?php

use App\Http\Responses\JsonErrorRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('api')->group(__DIR__.'/../routes/catalog.php');
            Route::middleware('api')->group(__DIR__.'/../routes/debug.php');
            Route::middleware([])->group(__DIR__.'/../routes/health.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => JsonErrorRenderer::wantsJson($request),
        );
        $exceptions->render(
            fn (Throwable $e, Request $request) => JsonErrorRenderer::render($e, $request),
        );
    })->create();
