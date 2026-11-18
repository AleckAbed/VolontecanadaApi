<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>{{ $isInvite ? 'Votre espace entreprise' : 'Nouveau mot de passe' }} — {{ $brand['name'] }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    @include('emails.partials.logo')
    <h1 style="font-size: 20px; color: {{ $brand['color_dark'] }};">{{ $brand['name'] }}</h1>
    <p>Bonjour {{ $name }},</p>
    @if ($isInvite)
        <p>{{ $brand['name'] }} vous ouvre un espace entreprise{{ $companyName ? ' pour ' . $companyName : '' }}. Vous pourrez y suivre l’avancement des dossiers d’immigration de vos employés, transmettre une demande pour un nouvel employé et consulter vos factures.</p>
        <p>Choisissez votre mot de passe pour activer votre accès :</p>
    @else
        <p>Vous avez demandé à réinitialiser le mot de passe de votre espace entreprise. Choisissez-en un nouveau :</p>
    @endif
    <p style="margin:24px 0"><a href="{{ $actionUrl }}" style="display:inline-block;background:{{ $brand['color'] }};color:#ffffff;padding:12px 26px;border-radius:8px;text-decoration:none;font-weight:bold">{{ $isInvite ? 'Activer mon accès' : 'Choisir un nouveau mot de passe' }}</a></p>
    <p style="font-size:13px;color:#64748b">Ce lien est personnel et expire bientôt. Ensuite, connectez-vous ici : <a href="{{ $loginUrl }}" style="color:#64748b">{{ $loginUrl }}</a></p>
    <p style="font-size:12px;color:#64748b">Vous voyez seulement ce que le cabinet partage pour chaque dossier ; les renseignements personnels de vos employés restent confidentiels.</p>
</body>
</html>
