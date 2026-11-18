<?php

namespace App\Http\Middleware;

use App\Models\Cabinet;
use App\Models\Collaborator;
use App\Models\DocumentRequest;
use App\Models\Invitation;
use App\Models\QuestionnaireRequest;
use App\Support\Tenancy\CurrentCabinet;
use Closure;
use Illuminate\Http\Request;

/**
 * Fixe le cabinet courant à partir du code ou du jeton d'un lien public.
 *
 * Usage : 'cabinet.code:invitation' | 'cabinet.code:questionnaire'
 *         | 'cabinet.code:document' | 'cabinet.code:activation'
 *
 * Le code est lu dans la route ({code} ou {token}) ou dans le corps
 * (code / token). Si rien ne correspond, le contexte reste vide : la
 * recherche du contrôleur, filtrée par cabinet, renverra alors 404 comme
 * pour un code invalide. Le contrôleur reste seul juge de la validité
 * (courriel, expiration, statut).
 */
class ResolveCabinetFromCode
{
    /** type => [modèle, colonne] */
    private const LOOKUPS = [
        'invitation' => [Invitation::class, 'unique_code'],
        'questionnaire' => [QuestionnaireRequest::class, 'unique_code'],
        'document' => [DocumentRequest::class, 'token'],
        'activation' => [Collaborator::class, 'activation_token'],
    ];

    public function handle(Request $request, Closure $next, string $type)
    {
        [$model, $column] = self::LOOKUPS[$type];

        $code = $request->route('code')
            ?? $request->route('token')
            ?? $request->input('code')
            ?? $request->input('token');

        if (is_string($code) && $code !== '') {
            $cabinetId = CurrentCabinet::withoutScope(
                fn () => $model::query()->where($column, $code)->value('cabinet_id')
            );

            if ($cabinetId) {
                $cabinet = Cabinet::find($cabinetId);
                if (! $cabinet || ! $cabinet->is_active) {
                    return response()->json([
                        'message' => 'Ce lien n\'est plus disponible.',
                    ], 403);
                }
                CurrentCabinet::set((int) $cabinetId);
            }
        }

        return $next($request);
    }
}
