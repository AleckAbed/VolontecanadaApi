<?php

use App\Models\PlatformAdmin;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;

// Rappels d'échéances chaque matin (nécessite le cron « php artisan schedule:run » en production).
Schedule::command('cabinet:deadline-reminders')->dailyAt('07:00')->timezone('America/Toronto');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Crée (ou met à jour le mot de passe d') un compte super-admin de la plateforme.
 * Le mot de passe est demandé de façon masquée : il n'apparaît ni à l'écran
 * ni dans l'historique du terminal.
 */
Artisan::command('platform:create-admin {email} {name}', function (string $email, string $name) {
    $password = $this->secret('Mot de passe (12 caractères minimum)');
    $confirm = $this->secret('Confirmez le mot de passe');

    $validator = Validator::make(
        ['email' => $email, 'password' => $password, 'password_confirmation' => $confirm],
        ['email' => 'required|email', 'password' => 'required|string|min:12|confirmed']
    );
    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }

    $admin = PlatformAdmin::updateOrCreate(['email' => $email], ['name' => $name, 'password' => $password]);
    $this->info(($admin->wasRecentlyCreated ? 'Compte créé' : 'Mot de passe mis à jour') . " : {$admin->email}");

    return 0;
})->purpose('Créer un compte super-admin de la plateforme');
