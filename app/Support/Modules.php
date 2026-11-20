<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\Cabinet;

/**
 * Menus (modules) que le propriétaire peut ouvrir ou masquer à ses membres (S21).
 *
 * Par défaut tout est ouvert, comme avant. Le réglage se fait par rôle (conseiller,
 * adjoint) et peut être précisé membre par membre. Les gestionnaires (propriétaire,
 * administrateur) voient toujours tout.
 *
 * L'API applique le même droit que le menu (middleware EnsureModuleAccess), à partir
 * des préfixes d'adresse ci-dessous. Pour les modules marqués « shared_read », la
 * lecture reste permise : ces listes servent aussi ailleurs (ex. choisir un
 * collaborateur ou un modèle depuis un dossier) ; seules les modifications sont fermées.
 */
final class Modules
{
    /** @var array<string, array{label: string, href: string, prefixes: list<string>, shared_read?: bool}> */
    public const ALL = [
        'statistics' => ['label' => 'Statistiques', 'href' => '/analytics', 'prefixes' => ['statistics']],
        'file_manager' => ['label' => 'Explorateur de fichiers', 'href' => '/file-manager', 'prefixes' => ['file-manager']],
        'companies' => ['label' => 'Entreprises', 'href' => '/admin/entreprises', 'prefixes' => ['companies', 'company-requests']],
        'deadlines' => ['label' => 'Échéances', 'href' => '/admin/echeances', 'prefixes' => ['deadlines']],
        'appointments' => ['label' => 'Rendez-vous', 'href' => '/admin/rendez-vous', 'prefixes' => ['booking', 'google']],
        'invoices' => ['label' => 'Facturation', 'href' => '/facturation', 'prefixes' => ['invoices']],
        'collaborators' => ['label' => 'Collaborateurs', 'href' => '/admin/collaborators', 'prefixes' => ['collaborators'], 'shared_read' => true],
        'envois' => ['label' => 'Envois', 'href' => '/envois', 'prefixes' => ['invitations'], 'shared_read' => true],
        'document_templates' => ['label' => 'Modèles de documents', 'href' => '/documents', 'prefixes' => ['document-templates', 'catalog'], 'shared_read' => true],
        'news' => ['label' => 'Actualités', 'href' => '/podcast', 'prefixes' => ['news']],
        'assistant' => ['label' => 'Assistant IA (Volo)', 'href' => '/assistant', 'prefixes' => ['chatbot']],
    ];

    /** Rôles que le propriétaire peut régler. */
    public const CONFIGURABLE_ROLES = [Roles::ADVISOR, Roles::ASSISTANT];

    /** @return array<string, bool> */
    public static function effective(Admin $admin, ?Cabinet $cabinet = null): array
    {
        $all = array_fill_keys(array_keys(self::ALL), true);
        if (in_array($admin->role, Roles::MANAGERS, true)) {
            return $all;
        }
        $cabinet ??= Cabinet::find($admin->cabinet_id);
        $byRole = (array) (($cabinet?->module_access ?? [])[$admin->role] ?? []);
        $own = (array) ($admin->module_access ?? []);

        foreach ($all as $key => $_) {
            if (array_key_exists($key, $own)) {
                $all[$key] = (bool) $own[$key];
            } elseif (array_key_exists($key, $byRole)) {
                $all[$key] = (bool) $byRole[$key];
            }
        }

        return $all;
    }

    /** Module dont dépend une adresse de l'API admin (« api/admin/invoices/{id} » → invoices). */
    public static function forUri(string $uri): ?string
    {
        $path = preg_replace('#^api/admin/#', '', $uri);
        $first = explode('/', (string) $path)[0] ?? '';
        foreach (self::ALL as $key => $module) {
            if (in_array($first, $module['prefixes'], true)) {
                return $key;
            }
        }

        return null;
    }

    /** Ne garde que des clés connues et des valeurs booléennes. */
    public static function clean(?array $map): array
    {
        return collect($map ?? [])
            ->only(array_keys(self::ALL))
            ->map(fn ($v) => (bool) $v)
            ->all();
    }

    public static function catalog(): array
    {
        return collect(self::ALL)->map(fn ($m, $key) => ['key' => $key, 'label' => $m['label'], 'href' => $m['href']])->values()->all();
    }
}
