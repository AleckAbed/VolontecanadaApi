<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class SetLocale
{
    private const SUPPORTED = ['fr', 'en', 'es'];
    private const FALLBACK = 'fr';

    public function handle(Request $request, Closure $next)
    {
        $lang = $request->header('Accept-Language', self::FALLBACK);
        // Extraire uniquement le code primaire ("fr-FR,en;q=0.9" -> "fr")
        $primary = strtolower(substr($lang, 0, 2));
        $locale = in_array($primary, self::SUPPORTED, true) ? $primary : self::FALLBACK;
        App::setLocale($locale);
        return $next($request);
    }
}
