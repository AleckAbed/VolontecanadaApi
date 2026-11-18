<?php

use App\Models\PlatformAdmin;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;

// Tâches planifiées : en production, le cron « * * * * * php artisan schedule:run » doit tourner
// (voir DEPLOIEMENT.md). Heures de l'Est.
Schedule::command('cabinet:deadline-reminders')->dailyAt('07:00')->timezone('America/Toronto');
Schedule::command('booking:send-reminders')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('cabinet:apply-retention')->dailyAt('03:00')->timezone('America/Toronto')->withoutOverlapping();
// Sauvegardes : seulement quand la clé est configurée (en développement, rien ne tourne).
// En production sans clé, aucune sauvegarde n'existe : la vérification de 4 h le signale dans les journaux.
Schedule::command('backup:run')->dailyAt('02:00')->timezone('America/Toronto')->withoutOverlapping()
    ->when(fn () => filled(config('backup.encryption_key')));
Schedule::command('backup:verify')->dailyAt('04:00')->timezone('America/Toronto')
    ->when(fn () => app()->isProduction() || filled(config('backup.encryption_key')));
Schedule::command('queue:prune-failed --hours=720')->weekly();
Schedule::command('sanctum:prune-expired --hours=48')->daily();
// Journal d'activité (2.6) : entrées plus anciennes que AUDIT_KEEP_DAYS supprimées chaque mois.
Schedule::call(function () {
    \Illuminate\Support\Facades\DB::table('audit_logs')->where('created_at', '<', now()->subDays(config('audit.keep_days')))->delete();
})->name('audit:prune')->monthly();

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

Artisan::command('backup:key', function () {
    $this->line(\App\Services\Backup\BackupService::generateKey());
    $this->comment('Mettez cette valeur dans BACKUP_ENCRYPTION_KEY et gardez-en une copie HORS du serveur : sans elle, les sauvegardes sont illisibles.');
})->purpose('Génère une clé de chiffrement des sauvegardes');

Artisan::command('backup:decrypt {name} {out}', function (string $name, string $out) {
    app(\App\Services\Backup\BackupService::class)->decryptTo($name, $out);
    $this->info("Déchiffrée vers {$out} (fichier .sql.gz). Restauration : gunzip -c {$out} | mysql <base>");
})->purpose('Déchiffre une sauvegarde vers un fichier .sql.gz local');
