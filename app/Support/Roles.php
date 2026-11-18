<?php

namespace App\Support;

/**
 * Rôles du personnel d'un cabinet.
 *
 *  - owner     (Propriétaire) : tout, y compris l'équipe, les rôles et (S6) l'abonnement.
 *  - admin     (Administrateur) : tout, sauf attribuer ou retirer le rôle de propriétaire.
 *  - advisor   (Conseiller) : travail sur les clients et dossiers, sans configuration ni équipe.
 *  - assistant (Adjoint) : comme le conseiller, sans suppression.
 */
final class Roles
{
    public const OWNER = 'owner';
    public const ADMIN = 'admin';
    public const ADVISOR = 'advisor';
    public const ASSISTANT = 'assistant';

    public const ALL = [self::OWNER, self::ADMIN, self::ADVISOR, self::ASSISTANT];

    /** Gèrent l'équipe et la configuration du cabinet. */
    public const MANAGERS = [self::OWNER, self::ADMIN];

    /** Peuvent supprimer des clients, dossiers, envois. */
    public const CAN_DELETE = [self::OWNER, self::ADMIN, self::ADVISOR];

    public const LABELS = [
        self::OWNER => 'Propriétaire',
        self::ADMIN => 'Administrateur',
        self::ADVISOR => 'Conseiller',
        self::ASSISTANT => 'Adjoint',
    ];

    public static function label(?string $role): string
    {
        return self::LABELS[$role] ?? (string) $role;
    }

    /** Droits envoyés au front pour adapter l'interface (le contrôle réel reste côté API). */
    public static function permissions(?string $role): array
    {
        return [
            'manage_team' => in_array($role, self::MANAGERS, true),
            'manage_settings' => in_array($role, self::MANAGERS, true),
            'delete_records' => in_array($role, self::CAN_DELETE, true),
            'assign_owner' => $role === self::OWNER,
        ];
    }
}
