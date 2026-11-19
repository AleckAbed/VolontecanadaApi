<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientProfile;
use App\Models\Dossier;
use App\Models\DocumentTemplate;
use App\Models\FamilyMember;
use App\Models\Invitation;
use App\Models\InvitationItem;
use App\Models\QuestionnaireRequest;
use App\Support\ClientProfileFields;

/**
 * « Le client répond une fois » :
 *  - le profil d'un client (ou d'un membre de sa famille) réunit ses réponses ;
 *  - chaque modèle PDF peut avoir une correspondance champ PDF → clé du profil ;
 *  - à l'ouverture d'un PDF, les champs correspondants sont préremplis.
 */
class ClientProfileService
{
    /** Code du questionnaire général dont les réponses alimentent le profil. */
    public const GENERAL_QUESTIONNAIRE = 'questionnaire_demandeur_001';

    /**
     * Profil complet : champs de base du dossier client, complétés par le profil enregistré.
     *
     * @return array<string, string>
     */
    public function resolve(Client $client, ?FamilyMember $member = null): array
    {
        $person = $member ?? $client;
        $base = ClientProfileFields::only([
            'firstName' => $person->first_name,
            'lastName' => $person->last_name,
            'email' => $person->email,
            'phone' => $person->phone,
            'dateOfBirth' => $person->date_of_birth ? \Illuminate\Support\Carbon::parse($person->date_of_birth)->format('Y-m-d') : null,
            'citizenship1' => $person->nationality,
            'country' => $person->country_of_residence,
            'passportNumber' => $person->passport_number,
        ]);

        $stored = ClientProfile::where('client_id', $client->id)
            ->where('family_member_id', $member?->id)
            ->value('data') ?? [];
        if (is_string($stored)) {
            $stored = json_decode($stored, true) ?: [];
        }

        return array_merge($base, ClientProfileFields::only($stored));
    }

    /** Enregistre des réponses dans le profil (seules les clés connues et non vides sont prises). */
    public function save(Client $client, ?FamilyMember $member, array $data): ClientProfile
    {
        $profile = ClientProfile::firstOrNew([
            'client_id' => $client->id,
            'family_member_id' => $member?->id,
        ]);

        $profile->data = array_merge($profile->data ?? [], ClientProfileFields::only($data));
        $profile->save();

        return $profile;
    }

    /** Remplace les valeurs du profil (édition depuis le portail : un champ vidé est retiré). */
    public function replace(Client $client, ?FamilyMember $member, array $data): ClientProfile
    {
        $profile = ClientProfile::firstOrNew([
            'client_id' => $client->id,
            'family_member_id' => $member?->id,
        ]);
        $profile->data = ClientProfileFields::only($data);
        $profile->save();

        return $profile;
    }

    /** Après enregistrement du questionnaire général : ses réponses alimentent le profil. */
    public function syncFromQuestionnaire(QuestionnaireRequest $qr): void
    {
        if ($qr->form_type !== self::GENERAL_QUESTIONNAIRE || ! $qr->client_id || ! is_array($qr->form_data)) {
            return;
        }

        $client = Client::find($qr->client_id);
        if (! $client) {
            return;
        }

        $memberId = $qr->invitation_item_id
            ? InvitationItem::find($qr->invitation_item_id)?->invitation?->family_member_id
            : null;
        $member = $memberId ? FamilyMember::where('client_id', $client->id)->find($memberId) : null;

        $this->save($client, $member, $qr->form_data);
    }

    /** Profil à utiliser pour les documents d'une invitation. */
    public function forInvitation(Invitation $invitation): array
    {
        if ($invitation->client_id && ($client = Client::find($invitation->client_id))) {
            $member = $invitation->family_member_id
                ? FamilyMember::where('client_id', $client->id)->find($invitation->family_member_id)
                : null;

            return $this->resolve($client, $member);
        }

        // Personne sans fiche client : on se sert du questionnaire général de la même invitation.
        $codes = $invitation->items()->whereNotNull('linked_questionnaire_code')->pluck('linked_questionnaire_code');
        $qr = QuestionnaireRequest::whereIn('unique_code', $codes)
            ->where('form_type', self::GENERAL_QUESTIONNAIRE)
            ->first();

        $data = is_array($qr?->form_data) ? $qr->form_data : [];
        $data['email'] = $data['email'] ?? $invitation->email;
        $data['phone'] = $data['phone'] ?? $invitation->phone;

        return ClientProfileFields::only($data);
    }

    /** Profil à utiliser pour les documents d'un dossier. */
    public function forDossier(Dossier $dossier): array
    {
        $client = Client::find($dossier->client_id);
        if (! $client) {
            return [];
        }
        $member = $dossier->scope === 'member' && $dossier->family_member_id
            ? FamilyMember::where('client_id', $client->id)->find($dossier->family_member_id)
            : null;

        return $this->resolve($client, $member);
    }

    /** Préremplissage d'un document de dossier (vue admin ou collaborateur). */
    public function prefillForDossierDocument(\App\Models\DossierDocument $doc): array
    {
        if (! $doc->document_template_id || ! ($dossier = Dossier::find($doc->dossier_id))) {
            return [];
        }

        return $this->prefill(DocumentTemplate::find($doc->document_template_id), $this->forDossier($dossier));
    }

    /**
     * Valeurs à injecter dans le PDF d'un modèle, au format du visualiseur.
     * Les clés « __as__… » (stockage pdf.js) attendent { value: … }.
     *
     * @return array<string, mixed>
     */
    public function prefill(?DocumentTemplate $template, array $profile): array
    {
        $map = $template?->field_map;
        if (! is_array($map) || $map === [] || $profile === []) {
            return [];
        }

        $out = [];
        foreach ($map as $fieldKey => $entry) {
            $profileKey = self::profileKeyOf($entry);
            if (! $profileKey || ! isset($profile[$profileKey])) {
                continue;
            }
            $value = $profile[$profileKey];
            $isStorage = str_starts_with((string) $fieldKey, '__as__');
            if (is_array($entry) && ! empty($entry['format'])) {
                $value = ClientProfileFields::formatDate($value, $entry['format']);
            }

            if (is_array($entry) && isset($entry['equals'])) {
                // Case à cocher, bouton radio ou option précise : valeur relevée au moment de la relier.
                if (ClientProfileFields::matches($profileKey, $value, (array) $entry['equals'])) {
                    $out[$fieldKey] = $entry['set'] ?? true;
                }
                continue;
            }
            if (is_array($entry) && ! empty($entry['choice'])) {
                // Liste déroulante : le visualiseur choisit l'option qui correspond à l'un de ces libellés.
                $choices = ClientProfileFields::aliases($profileKey, $value);
                $out[$fieldKey] = $isStorage ? ['value' => $value, 'choices' => $choices] : ['choices' => $choices];
                continue;
            }
            $out[$fieldKey] = $isStorage ? ['value' => $value] : $value;
        }

        return $out;
    }

    /** Clé du profil d'une entrée de correspondance (texte simple, ou entrée détaillée). */
    public static function profileKeyOf(mixed $entry): ?string
    {
        if (is_string($entry)) {
            return $entry;
        }

        return is_array($entry) && is_string($entry['key'] ?? null) ? $entry['key'] : null;
    }

    /** Entrée reliée en cliquant (case, radio, liste, champ texte), et non par un jeton tapé dans le champ. */
    public static function isRule(mixed $entry): bool
    {
        return is_array($entry) && (isset($entry['equals']) || ! empty($entry['choice']) || ! empty($entry['click']));
    }

    /**
     * Construit une correspondance à partir d'un PDF où l'admin a tapé des jetons
     * (@nom, @passeport…) dans les champs.
     *
     * @return array{map: array<string, string>, unknown: string[]}
     */
    public function mapFromTokens(array $formData): array
    {
        $tokens = ClientProfileFields::tokens();
        $map = [];
        $unknown = [];

        foreach ($formData as $fieldKey => $raw) {
            $value = is_array($raw) && array_key_exists('value', $raw) ? $raw['value'] : $raw;
            if (! is_string($value)) {
                continue;
            }
            $value = strtolower(trim($value));
            if (! str_starts_with($value, '@')) {
                continue;
            }
            // Format de date facultatif : @date_naissance:jj/mm/aaaa, ou une partie (:aaaa, :mm, :jj).
            [$token, $format] = array_pad(explode(':', $value, 2), 2, null);
            if (! isset($tokens[$token]) || ($format !== null && ! preg_match(ClientProfileFields::DATE_FORMAT, $format))) {
                $unknown[] = $value;
                continue;
            }
            $map[(string) $fieldKey] = $format !== null && $format !== ''
                ? ['key' => $tokens[$token], 'format' => $format]
                : $tokens[$token];
        }

        return ['map' => $map, 'unknown' => array_values(array_unique($unknown))];
    }

    /** Inverse : les jetons à réafficher dans le PDF pour montrer la correspondance actuelle. */
    public function tokensForMap(array $map): array
    {
        $out = [];
        foreach ($map as $fieldKey => $entry) {
            if (self::isRule($entry) || ! ($token = ClientProfileFields::tokenFor((string) self::profileKeyOf($entry)))) {
                continue;
            }
            if (is_array($entry) && ! empty($entry['format'])) {
                $token .= ':' . $entry['format'];
            }
            $out[$fieldKey] = str_starts_with((string) $fieldKey, '__as__') ? ['value' => $token] : $token;
        }

        return $out;
    }
}
