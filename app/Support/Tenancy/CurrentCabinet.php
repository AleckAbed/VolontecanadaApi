<?php

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * Cabinet courant de la requête.
 *
 * Fixé une fois par requête :
 *  - par ResolveCabinetFromUser (admin, collaborateur ou client authentifié) ;
 *  - par ResolveCabinetFromCode (liens publics : invitation, questionnaire,
 *    document, activation de collaborateur).
 *
 * Tant qu'aucun cabinet n'est fixé, CabinetScope ne renvoie AUCUNE ligne
 * (échec fermé) : un oubli donne une page vide, jamais une fuite de données.
 *
 * Exceptions au filtrage (bypass) :
 *  - commandes artisan, migrations, tinker, workers de file (hors tests) ;
 *  - blocs explicites CurrentCabinet::withoutScope(fn () => ...), réservés à la
 *    connexion et à la résolution du cabinet.
 */
final class CurrentCabinet
{
    private static ?int $id = null;

    private static int $bypassDepth = 0;

    public static function set(?int $id): void
    {
        self::$id = $id;
    }

    public static function id(): ?int
    {
        return self::$id;
    }

    public static function check(): bool
    {
        return self::$id !== null;
    }

    /** Identifiant du cabinet courant, ou exception si aucun n'est fixé. */
    public static function require(): int
    {
        if (self::$id === null) {
            throw new RuntimeException('Aucun cabinet courant : impossible de continuer sans contexte de cabinet.');
        }

        return self::$id;
    }

    public static function clear(): void
    {
        self::$id = null;
        self::$bypassDepth = 0;
    }

    /**
     * Exécute $callback sans filtrage par cabinet.
     * Réservé à la connexion et à la résolution du cabinet depuis un code public.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutScope(callable $callback): mixed
    {
        self::$bypassDepth++;
        try {
            return $callback();
        } finally {
            self::$bypassDepth--;
        }
    }

    /**
     * Exécute $callback dans le contexte d'un cabinet donné, puis restaure le
     * contexte précédent (utile pour les tests et les commandes).
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function runAs(int $cabinetId, callable $callback): mixed
    {
        $previous = self::$id;
        self::$id = $cabinetId;
        try {
            return $callback();
        } finally {
            self::$id = $previous;
        }
    }

    /** Contournement explicite en cours (withoutScope). */
    public static function isExplicitlyBypassed(): bool
    {
        return self::$bypassDepth > 0;
    }

    /**
     * Filtrage désactivé : contournement explicite, ou console sans cabinet fixé
     * (artisan, migrations, tinker, workers). Pendant les tests, jamais de
     * contournement implicite : on veut le comportement réel.
     */
    public static function isBypassed(): bool
    {
        if (self::$bypassDepth > 0) {
            return true;
        }

        if (self::$id !== null) {
            return false;
        }

        $app = app();

        return $app->runningInConsole() && ! $app->runningUnitTests();
    }

    /**
     * Chemin de stockage rangé par cabinet : "cabinets/{id}/{relatif}".
     */
    public static function storagePath(string $relative): string
    {
        return 'cabinets/' . self::require() . '/' . ltrim($relative, '/');
    }
}
