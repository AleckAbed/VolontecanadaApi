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
        $middleware->api(prepend: [
            \App\Http\Middleware\ResetCabinetContext::class,
            \Illuminate\Http\Middleware\HandleCors::class,
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\AuthCookieToBearer::class,
        ]);
        // Sécurité : en-têtes sur chaque réponse, antivirus sur chaque fichier reçu.
        $middleware->api(append: [
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\ScanUploads::class,
        ]);

        // Multi-cabinets : voir app/Support/Tenancy.
        $middleware->alias([
            'cabinet' => \App\Http\Middleware\ResolveCabinetFromUser::class,
            'cabinet.code' => \App\Http\Middleware\ResolveCabinetFromCode::class,
            'role' => \App\Http\Middleware\EnsureAdminRole::class,
            'audit' => \App\Http\Middleware\AuditAccess::class,
            'two-factor' => \App\Http\Middleware\EnsureTwoFactor::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Suivi des erreurs (inactif sans SENTRY_LARAVEL_DSN).
        \Sentry\Laravel\Integration::handles($exceptions);

        // Pour les routes API : si non authentifié, renvoyer 401 JSON au lieu de rediriger vers [login].
        $exceptions->renderable(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });
    })->create();
