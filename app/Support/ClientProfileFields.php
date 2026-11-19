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
        'permitExpiryDate' => ['@permis_expiration', 'Date d\'expiration du permis ou du statut actuel', 'Immigration et parcours', 'date'],
        'lastEntryDate' =>['@derniere_entree', 'Date de la dernière entrée au Canada', 'Immigration et parcours', 'date'],
        'lastEntryPlace' => ['@lieu_derniere_entree', 'Lieu de la dernière entrée au Canada', 'Immigration et parcours', 'text'],
        'preferredLanguageCorrespondence' => ['@langue', 'Langue de correspondance', 'Immigration et parcours', 'text'],
        'highestEducationLevel' => ['@scolarite', 'Plus haut niveau de scolarité', 'Immigration et parcours', 'text'],
        'currentEmployment' => ['@emploi_actuel', 'Emploi actuel', 'Immigration et parcours', 'text'],
    ];

    /**
     * Valeurs codées du questionnaire général, avec leurs libellés (fr, en) :
     * servent à cocher la bonne case et à choisir la bonne option d'une liste.
     */
    public const CHOICES = [
        'sex' => [
            'male' => ['Masculin', 'Male', 'M'],
            'female' => ['Féminin', 'Female', 'F'],
            'other' => ['Autre', 'Un autre genre', 'Another gender', 'Unspecified', 'X'],
        ],
        'currentMaritalStatus' => [
            'single' => ['Célibataire', 'Single', 'Never married'],
            'married' => ['Marié(e)', 'Married'],
            'common-law' => ['Union de fait', 'Conjoint(e) de fait', 'Common-law'],
            'divorced' => ['Divorcé(e)', 'Divorced'],
            'separated' => ['Séparé(e)', 'Séparé légalement', 'Separated', 'Legally separated'],
            'widowed' => ['Veuf(ve)', 'Widowed'],
        ],
        'eyeColor' => [
            'blue' => ['Bleu', 'Blue'],
            'green' => ['Vert', 'Green'],
            'brown' => ['Marron', 'Brown'],
            'black' => ['Noir', 'Black'],
            'gray' => ['Gris', 'Grey', 'Gray'],
            'hazel' => ['Noisette', 'Hazel'],
        ],
        'preferredLanguageCorrespondence' => [
            'Français' => ['French'],
            'Anglais' => ['English'],
        ],
    ];

    /** Provinces et territoires : nom (fr, en) => code des listes IRCC. */
    public const PROVINCES = [
        'AB' => ['Alberta'], 'BC' => ['Colombie-Britannique', 'British Columbia'], 'MB' => ['Manitoba'],
        'NB' => ['Nouveau-Brunswick', 'New Brunswick'], 'NL' => ['Terre-Neuve-et-Labrador', 'Newfoundland and Labrador'],
        'NS' => ['Nouvelle-Écosse', 'Nova Scotia'], 'NT' => ['Territoires du Nord-Ouest', 'Northwest Territories'],
        'NU' => ['Nunavut'], 'ON' => ['Ontario'], 'PE' => ['Île-du-Prince-Édouard', 'Prince Edward Island'],
        'QC' => ['Québec', 'Quebec'], 'SK' => ['Saskatchewan'], 'YT' => ['Yukon'],
    ];

    /** Format de date accepté après un jeton (@date_naissance:jj/mm/aaaa, :aaaa, :mm, :jj). */
    public const DATE_FORMAT = '/^(?=.*(aaaa|aa|mm|jj|yyyy|yy|dd))[ajmyd\/\-\. ]{1,12}$/';

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
            'choices' => isset(self::CHOICES[$key])
                ? collect(self::CHOICES[$key])->map(fn ($labels, $value) => ['value' => (string) $value, 'label' => $labels[0]])->values()->all()
                : null,
        ])->values()->all();
    }

    /** La valeur et ses libellés connus : de quoi la reconnaître dans une liste du PDF. */
    public static function aliases(string $key, string $value): array
    {
        $labels = self::CHOICES[$key][$value] ?? [];
        if ($key === 'province') {
            foreach (self::PROVINCES as $code => $names) {
                $all = array_merge([$code], $names);
                if (in_array(self::normalize($value), array_map([self::class, 'normalize'], $all), true)) {
                    $labels = $all;
                }
            }
        }

        return array_values(array_unique(array_merge([$value], $labels)));
    }

    /** Comparaison souple : casse, accents et ponctuation ignorés. */
    public static function normalize(string $value): string
    {
        $ascii = strtolower(\Illuminate\Support\Str::ascii($value));

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $ascii));
    }

    /** Vrai si la valeur du profil correspond à l'une des valeurs attendues (code ou libellé). */
    public static function matches(string $key, string $profileValue, array $expected): bool
    {
        $have = array_map([self::class, 'normalize'], self::aliases($key, $profileValue));
        foreach ($expected as $want) {
            if (in_array(self::normalize((string) $want), $have, true)) {
                return true;
            }
        }

        return false;
    }

    /** Date du profil au format demandé (jj/mm/aaaa, aaaa, mm, jj…), ou telle quelle si illisible. */
    public static function formatDate(string $value, string $format): string
    {
        try {
            $date = \Illuminate\Support\Carbon::parse($value);
        } catch (\Throwable) {
            return $value;
        }
        $php = strtr(strtolower($format), ['aaaa' => 'Y', 'yyyy' => 'Y', 'aa' => 'y', 'yy' => 'y', 'mm' => 'm', 'jj' => 'd', 'dd' => 'd']);

        return $date->format($php);
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
