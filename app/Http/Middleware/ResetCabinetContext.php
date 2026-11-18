<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentCabinet;
use Closure;
use Illuminate\Http\Request;

/**
 * Remet le cabinet courant à zéro au début de chaque requête API.
 * Le contexte est statique : sans cette remise à zéro, une requête pourrait
 * hériter du cabinet de la précédente (tests, serveurs persistants).
 */
class ResetCabinetContext
{
    public function handle(Request $request, Closure $next)
    {
        CurrentCabinet::clear();

        try {
            return $next($request);
        } finally {
            CurrentCabinet::clear();
        }
    }
}
