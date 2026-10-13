<?php

use App\Http\Middleware\EnsureOwner;
use App\Http\Middleware\SecureResponses;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Env;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['owner' => EnsureOwner::class]);
        $middleware->append(SecureResponses::class);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['password', 'password_confirmation']);
    })
    ->create();

// Tests use the public template instead of reading a private local environment.
if (Env::get('APP_ENV') === 'testing') {
    $app->loadEnvironmentFrom('.env.example');
}

return $app;
