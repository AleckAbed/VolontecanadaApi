<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Limiteurs nommés : chaque usage a son propre compteur.
     *
     * Avec `throttle:10,1`, Laravel partage un seul compteur par IP entre toutes
     * les routes qui ont la même limite : les connexions admin épuisaient le
     * quota des vérifications de questionnaire, et tout un cabinet derrière la
     * même IP se bloquait mutuellement.
     */
    private function configureRateLimiting(): void
    {
        // Connexion (admin, client, collaborateur) : 10/min par courriel + IP,
        // et 50/min par IP pour freiner le balayage de nombreux courriels.
        RateLimiter::for('login', function (Request $request) {
            $email = strtolower((string) $request->input('email'));
            return [
                Limit::perMinute(10)->by('login:' . $email . '|' . $request->ip()),
                Limit::perMinute(50)->by('login-ip:' . $request->ip()),
            ];
        });

        // Vérification courriel + code (invitations, questionnaires) : anti-bruteforce.
        RateLimiter::for('public-verify', fn (Request $request) =>
            Limit::perMinute(10)->by('verify:' . $request->path() . '|' . $request->ip()));

        // Lecture publique via code/jeton (affichage, PDF).
        RateLimiter::for('public-read', fn (Request $request) =>
            Limit::perMinute(30)->by('read:' . $request->ip()));

        // Écriture publique via code/jeton (sauvegarde auto, dépôt, soumission).
        RateLimiter::for('public-write', fn (Request $request) =>
            Limit::perMinute(60)->by('write:' . $request->ip()));
    }
}
