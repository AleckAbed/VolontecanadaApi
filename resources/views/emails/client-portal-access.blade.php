<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>{{ $isInvite ? 'Votre portail client' : 'Réinitialisation du mot de passe' }} — {{ $cabinetName }}</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 620px; margin: 0 auto; padding: 20px; background-color: #fafafa; }
        .header { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: white; padding: 28px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background-color: #ffffff; padding: 30px; border: 1px solid #e5e7eb; }
        .button { display: inline-block; background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: #ffffff !important; padding: 14px 32px; text-decoration: none; border-radius: 8px; margin: 20px 0; font-weight: bold; }
        .info { background: #eff6ff; border-left: 4px solid #2563eb; padding: 14px 16px; margin: 18px 0; border-radius: 4px; font-size: 14px; }
        .warning { background-color: #fef3c7; border-left: 4px solid #f59e0b; padding: 14px 16px; margin: 20px 0; border-radius: 4px; font-size: 13px; }
        .footer { background-color: #eff6ff; padding: 18px; text-align: center; font-size: 12px; color: #475569; border-radius: 0 0 8px 8px; border-top: 2px solid #dbeafe; }
    </style>
</head>
<body>
    <div class="header">
        <h1 style="margin:0">{{ $cabinetName }}</h1>
        <p style="margin:6px 0 0;opacity:.9">{{ $isInvite ? 'Votre portail client' : 'Réinitialisation du mot de passe' }}</p>
    </div>

    <div class="content">
        <p>Bonjour <strong>{{ $client->first_name }} {{ $client->last_name }}</strong>,</p>

        @if ($isInvite)
            <p><strong>{{ $cabinetName }}</strong> vous ouvre un portail personnel pour suivre votre dossier d'immigration.</p>
            <div class="info">
                Vous y retrouverez vos dossiers, les formulaires et documents à compléter, et votre profil.
                <strong>Vous ne répondez qu'une fois</strong> : vos informations servent ensuite à préremplir vos formulaires.
            </div>
            <p>Pour activer votre accès, choisissez votre mot de passe :</p>
        @else
            <p>Pour choisir un nouveau mot de passe pour votre portail client :</p>
        @endif

        <p style="text-align:center;margin:24px 0">
            <a href="{{ $actionUrl }}" class="button">{{ $isInvite ? 'Activer mon portail' : 'Choisir un nouveau mot de passe' }}</a>
        </p>

        <p style="font-size:13px;color:#64748b;text-align:center;word-break:break-all">
            Ou copiez ce lien dans votre navigateur :<br>
            <span style="color:#2563eb">{{ $actionUrl }}</span>
        </p>

        <div class="warning">
            <strong>Important :</strong> ce lien expire dans <strong>{{ $isInvite ? '7 jours' : '1 heure' }}</strong> et ne fonctionne qu'une fois.
        </div>

        <p style="font-size:13px;color:#64748b">Ensuite, connectez-vous ici : <a href="{{ $loginUrl }}" style="color:#2563eb">{{ $loginUrl }}</a></p>
    </div>

    <div class="footer">
        {{ $cabinetName }}<br>
        Si vous n'êtes pas à l'origine de cette demande, ignorez ce courriel.
    </div>
</body>
</html>
