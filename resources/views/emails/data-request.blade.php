<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Votre demande de suppression de données — {{ $cabinetName }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 620px; margin: 0 auto; padding: 20px;">
    <h1 style="font-size: 20px; color: #1d4ed8;">{{ $cabinetName }}</h1>
    <p>Bonjour {{ $name }},</p>

    @if ($completed)
        <p>Votre demande de suppression a été traitée : vos renseignements personnels, vos dossiers et les documents
            que vous nous avez transmis ont été détruits.</p>
        <p>Si des factures vous ont été émises, la loi nous oblige à les conserver avec votre nom et votre courriel
            pendant la durée prévue par les règles fiscales. Votre accès au portail est fermé.</p>
    @else
        <p>Nous ne pouvons pas donner suite à votre demande de suppression pour le moment.</p>
    @endif

    @if ($response)
        <p style="background:#f1f5f9;padding:12px 14px;border-radius:6px"><strong>Message du cabinet :</strong><br>{!! nl2br(e($response)) !!}</p>
    @endif

    <p>Pour toute question, répondez à ce courriel ou communiquez avec le cabinet.</p>
    <p style="font-size:12px;color:#64748b">{{ $cabinetName }}</p>
</body>
</html>
