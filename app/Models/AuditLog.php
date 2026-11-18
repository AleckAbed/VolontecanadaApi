<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Entrée du journal d'activité (2.6). Ajout seulement : une entrée ne se modifie
 * ni ne se supprime par l'application (seul le nettoyage planifié efface les plus anciennes).
 */
class AuditLog extends Model
{
    use BelongsToCabinet;

    public const UPDATED_AT = null;

    public const ACTIONS = [
        'viewed' => 'A consulté',
        'downloaded' => 'A téléchargé',
        'exported' => 'A exporté',
        'created' => 'A créé',
        'updated' => 'A modifié',
        'deleted' => 'A supprimé',
        'login' => 'Connexion',
        'login_failed' => 'Échec de connexion',
        'logout' => 'Déconnexion',
        'two_factor_enabled' => 'Double authentification activée',
        'two_factor_disabled' => 'Double authentification désactivée',
        'two_factor_reset' => 'Double authentification réinitialisée',
        'two_factor_failed' => 'Code de double authentification refusé',
        'recovery_code_used' => 'Code de secours utilisé',
    ];

    public const SUBJECTS = [
        'client' => 'Client',
        'family_member' => 'Membre de la famille',
        'dossier' => 'Dossier',
        'dossier_document' => 'Document du dossier',
        'dossier_upload' => 'Fichier du dossier',
        'supplementary_file' => 'Pièce du dossier',
        'invitation' => 'Envoi',
        'invitation_item' => 'Formulaire d’un envoi',
        'invitation_upload' => 'Fichier transmis par le client',
        'document_request' => 'Document à remplir',
        'questionnaire' => 'Questionnaire',
        'invoice' => 'Facture',
        'mandate' => 'Mandat',
        'appointment' => 'Rendez-vous',
        'deadline' => 'Échéance',
        'admin' => 'Membre de l’équipe',
        'collaborator' => 'Collaborateur',
        'file_item' => 'Fichier',
        'cabinet' => 'Réglages du cabinet',
        'chatbot_settings' => 'Réglages de l’assistant IA',
        'data_request' => 'Demande sur les données',
    ];

    protected $fillable = [
        'cabinet_id', 'actor_type', 'actor_id', 'actor_name', 'action', 'subject_type', 'subject_id',
        'dossier_id', 'client_id', 'fields', 'ip', 'user_agent',
    ];

    protected $casts = ['fields' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Le journal d’activité ne se modifie pas.'));
        static::deleting(fn () => throw new LogicException('Le journal d’activité ne se supprime pas entrée par entrée.'));
    }
}
