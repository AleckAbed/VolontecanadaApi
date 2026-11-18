<?php

namespace App\Support;

use App\Models\Admin;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Support\Str;

/**
 * Liens à usage unique pour le personnel : invitation dans l'équipe ou
 * réinitialisation du mot de passe.
 *
 * Seule l'empreinte SHA-256 du jeton est stockée : une fuite de la base ne
 * permet pas d'utiliser les liens en cours. Émettre un nouveau jeton annule
 * le précédent.
 */
final class AdminAccessTokens
{
    public const INVITE = 'invite';
    public const RESET = 'reset';

    private const TTL = [
        self::INVITE => 7 * 24 * 60, // 7 jours
        self::RESET => 60,           // 1 heure
    ];

    /** Émet un jeton et renvoie sa valeur en clair (à mettre dans le lien). */
    public static function issue(Admin $admin, string $purpose): string
    {
        $plain = Str::random(64);

        $admin->forceFill([
            'access_token_hash' => hash('sha256', $plain),
            'access_token_purpose' => $purpose,
            'access_token_expires_at' => now()->addMinutes(self::TTL[$purpose]),
        ])->save();

        return $plain;
    }

    /**
     * Retrouve l'admin d'un jeton valide (non expiré), sans filtrage par
     * cabinet : c'est le jeton qui désigne le cabinet.
     */
    public static function find(string $plain): ?Admin
    {
        if ($plain === '') {
            return null;
        }

        $admin = CurrentCabinet::withoutScope(
            fn () => Admin::acrossCabinets()->where('access_token_hash', hash('sha256', $plain))->first()
        );

        if (! $admin || ! $admin->access_token_expires_at || $admin->access_token_expires_at->isPast()) {
            return null;
        }

        return $admin;
    }

    public static function consume(Admin $admin): void
    {
        $admin->forceFill([
            'access_token_hash' => null,
            'access_token_purpose' => null,
            'access_token_expires_at' => null,
        ])->save();
    }

    /** Règles de robustesse du mot de passe (mêmes que l'activation des collaborateurs). */
    public static function passwordRules(): array
    {
        return [
            'required', 'string', 'confirmed', 'min:8',
            'regex:/[A-Z]/', 'regex:/[a-z]/', 'regex:/[0-9]/', 'regex:/[^A-Za-z0-9]/',
        ];
    }

    public static function passwordMessages(): array
    {
        return [
            'password.regex' => 'Le mot de passe doit contenir au moins une majuscule, une minuscule, un chiffre et un caractère spécial.',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
            'password.min' => 'Le mot de passe doit faire au moins 8 caractères.',
        ];
    }
}
