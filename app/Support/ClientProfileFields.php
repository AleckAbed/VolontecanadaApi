<?php

namespace App\Support;

/**
 * Champs du profil d'immigration d'un client.
 *
 * Les clés reprennent celles du questionnaire général (client-form) : ses
 * réponses alimentent le profil sans conversion. Chaque champ a un jeton
 * (@nom, @passeport…) que l'admin tape dans un modèle PDF pour indiquer
 * « ce champ = cette information du profil » (voir FieldMapController).
 */
final class ClientProfileFields
{
    /** clé => [jeton, libellé, section, type] */
    public const FIELDS = [
        // Identité
        'lastName' => ['@nom', 'Nom de famille', 'Identité', 'text'],
        'firstName' => ['@prenom', 'Prénom(s)', 'Identité', 'text'],
        'uci' => ['@uci', 'Identifiant client unique (UCI)', 'Identité', 'text'],
        'sex' => ['@sexe', 'Sexe', 'Identité', 'text'],
        'dateOfBirth' => ['@date_naissance', 'Date de naissance', 'Identité', 'date'],
        'placeOfBirth' => ['@lieu_naissance', 'Ville de naissance', 'Identité', 'text'],
        'countryOfBirth' => ['@pays_naissance', 'Pays de naissance', 'Identité', 'text'],
        'citizenship1' => ['@citoyennete', 'Citoyenneté', 'Identité', 'text'],
        'eyeColor' => ['@yeux', 'Couleur des yeux', 'Identité', 'text'],
        'height' => ['@taille', 'Taille (cm)', 'Identité', 'text'],

        // État civil
        'currentMaritalStatus' => ['@etat_civil', 'État civil', 'État civil', 'text'],
        'marriageDate' => ['@date_mariage', 'Date du mariage ou de l\'union', 'État civil', 'date'],
        'spouseLastName' => ['@conjoint_nom', 'Nom du conjoint', 'État civil', 'text'],
        'spouseFirstName' => ['@conjoint_prenom', 'Prénom du conjoint', 'État civil', 'text'],

        // Coordonnées
        'email' => ['@courriel', 'Courriel', 'Coordonnées', 'email'],
        'phone' => ['@telephone', 'Téléphone', 'Coordonnées', 'text'],
        'apartmentUnit' => ['@appartement', 'Appartement / unité', 'Coordonnées', 'text'],
        'streetNumber' => ['@numero_rue', 'Numéro civique', 'Coordonnées', 'text'],
        'streetName' => ['@rue', 'Rue', 'Coordonnées', 'text'],
        'city' => ['@ville', 'Ville', 'Coordonnées', 'text'],
        'province' => ['@province', 'Province / État', 'Coordonnées', 'text'],
        'country' => ['@pays', 'Pays de résidence', 'Coordonnées', 'text'],
        'postalCode' => ['@code_postal', 'Code postal', 'Coordonnées', 'text'],

        // Pièces d'identité
        'passportNumber' => ['@passeport', 'Numéro de passeport', 'Pièces d\'identité', 'text'],
        'passportIssueCountry' => ['@passeport_pays', 'Pays de délivrance du passeport', 'Pièces d\'identité', 'text'],
        'passportIssueDate' => ['@passeport_emission', 'Date de délivrance du passeport', 'Pièces d\'identité', 'date'],
        'passportExpiryDate' => ['@passeport_expiration', 'Date d\'expiration du passeport', 'Pièces d\'identité', 'date'],
        'nationalIdNumber' => ['@id_national', 'Numéro de pièce d\'identité nationale', 'Pièces d\'identité', 'text'],
        'nationalIdIssueCountry' => ['@id_national_pays', 'Pays de la pièce d\'identité nationale', 'Pièces d\'identité', 'text'],

        // Immigration et parcours
        'lastEntryDate' => ['@derniere_entree', 'Date de la dernière entrée au Canada', 'Immigration et parcours', 'date'],
        'lastEntryPlace' => ['@lieu_derniere_entree', 'Lieu de la dernière entrée au Canada', 'Immigration et parcours', 'text'],
        'preferredLanguageCorrespondence' => ['@langue', 'Langue de correspondance', 'Immigration et parcours', 'text'],
        'highestEducationLevel' => ['@scolarite', 'Plus haut niveau de scolarité', 'Immigration et parcours', 'text'],
        'currentEmployment' => ['@emploi_actuel', 'Emploi actuel', 'Immigration et parcours', 'text'],
    ];

    public static function keys(): array
    {
        return array_keys(self::FIELDS);
    }

    /** jeton => clé */
    public static function tokens(): array
    {
        $out = [];
        foreach (self::FIELDS as $key => [$token]) {
            $out[$token] = $key;
        }

        return $out;
    }

    public static function tokenFor(string $key): ?string
    {
        return self::FIELDS[$key][0] ?? null;
    }

    /** Définitions pour le front (formulaire du portail, légende des jetons). */
    public static function definitions(): array
    {
        return collect(self::FIELDS)->map(fn ($f, $key) => [
            'key' => $key,
            'token' => $f[0],
            'label' => $f[1],
            'section' => $f[2],
            'type' => $f[3],
        ])->values()->all();
    }

    /** Ne garde que les clés du profil, en valeurs texte non vides. */
    public static function only(array $data): array
    {
        $out = [];
        foreach (self::keys() as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if (is_bool($value)) {
                $value = $value ? 'Oui' : 'Non';
            }
            if (is_scalar($value) && trim((string) $value) !== '') {
                $out[$key] = trim((string) $value);
            }
        }

        return $out;
    }
}
