@php
    $labels = [
        'client' => 'Vos informations', 'profil_immigration' => 'Profil d’immigration', 'membres_de_la_famille' => 'Membres de la famille',
        'dossiers' => 'Dossiers', 'questionnaires' => 'Questionnaires', 'rendez_vous' => 'Rendez-vous', 'mandats' => 'Mandats', 'factures' => 'Factures',
        'first_name' => 'Prénom', 'last_name' => 'Nom', 'email' => 'Courriel', 'phone' => 'Téléphone', 'date_of_birth' => 'Date de naissance',
        'nationality' => 'Nationalité', 'country_of_residence' => 'Pays de résidence', 'in_canada' => 'Au Canada', 'passport_number' => 'Numéro de passeport',
        'address' => 'Adresse', 'created_at' => 'Créé le', 'relationship' => 'Lien', 'nom' => 'Nom', 'service' => 'Service', 'statut' => 'Statut',
        'ouvert_le' => 'Ouvert le', 'date_limite' => 'Date limite', 'ferme_le' => 'Fermé le', 'reponses' => 'Réponses', 'cree_le' => 'Créé le',
        'starts_at' => 'Début', 'ends_at' => 'Fin', 'status' => 'Statut', 'meeting_mode' => 'Mode', 'notes' => 'Notes', 'title' => 'Titre',
        'body' => 'Texte', 'sent_at' => 'Envoyé le', 'signed_name' => 'Signé par', 'signed_at' => 'Signé le', 'signed_ip' => 'Adresse IP de signature',
        'number' => 'Numéro', 'currency' => 'Devise', 'issued_at' => 'Émise le', 'due_date' => 'Échéance', 'subtotal_cents' => 'Sous-total (cents)',
        'tax_cents' => 'Taxes (cents)', 'total_cents' => 'Total (cents)', 'paid_at' => 'Payée le',
    ];
    $label = fn ($k) => $labels[$k] ?? ucfirst(str_replace('_', ' ', (string) $k));
    $show = function ($v) use (&$show, $label) {
        if (is_bool($v)) return $v ? 'Oui' : 'Non';
        if ($v === null || $v === '') return '—';
        if (is_object($v)) $v = (array) $v;
        if (is_array($v)) {
            $out = '<dl>';
            foreach ($v as $k => $x) { $out .= '<dt>' . e($label($k)) . '</dt><dd>' . $show($x) . '</dd>'; }
            return $out . '</dl>';
        }
        return nl2br(e((string) $v));
    };
@endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Vos renseignements personnels</title>
<style>
    body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; color: #1f2937; max-width: 900px; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; }
    h1 { font-size: 1.6rem; margin-bottom: .25rem; }
    h2 { font-size: 1.15rem; margin-top: 2rem; border-bottom: 2px solid #e5e7eb; padding-bottom: .3rem; }
    .meta { color: #6b7280; font-size: .9rem; }
    .card { border: 1px solid #e5e7eb; border-radius: 10px; padding: .75rem 1rem; margin: .75rem 0; background: #fafafa; }
    dl { display: grid; grid-template-columns: minmax(150px, 30%) 1fr; gap: .25rem 1rem; margin: 0; }
    dt { font-weight: 600; color: #374151; }
    dd { margin: 0; word-break: break-word; }
    dd dl { border-left: 2px solid #e5e7eb; padding-left: .75rem; }
    .empty { color: #9ca3af; font-style: italic; }
    @media print { body { margin: 0; } .card { break-inside: avoid; } }
</style>
</head>
<body>
<h1>Vos renseignements personnels</h1>
<p class="meta">{{ $cabinet ? 'Détenus par ' . $cabinet . ' · ' : '' }}Copie produite le {{ $date }}. Les documents que vous avez transmis sont dans le dossier « fichiers » de cette archive.</p>

@foreach ($data as $section => $value)
    @continue($section === 'exporte_le')
    <h2>{{ $label($section) }}</h2>
    @php $items = is_iterable($value) ? collect($value)->all() : [$value]; $isList = array_is_list($items); @endphp
    @if (empty($items))
        <p class="empty">Aucun élément.</p>
    @elseif ($isList)
        @foreach ($items as $item)
            <div class="card">{!! $show($item) !!}</div>
        @endforeach
    @else
        <div class="card">{!! $show($items) !!}</div>
    @endif
@endforeach
</body>
</html>
