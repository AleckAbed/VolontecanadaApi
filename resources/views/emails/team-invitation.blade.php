<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Invitation — {{ $cabinetName }}</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 620px; margin: 0 auto; padding: 20px; background-color: #fafafa; }
        .header { background: linear-gradient(135deg, {{ $brand['color'] }} 0%, {{ $brand['color_dark'] }} 100%); color: white; padding: 28px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background-color: #ffffff; padding: 30px; border: 1px solid #e5e7eb; }
        .button { display: inline-block; background: linear-gradient(135deg, {{ $brand['color'] }} 0%, {{ $brand['color_dark'] }} 100%); color: #ffffff !important; padding: 14px 32px; text-decoration: none; border-radius: 8px; margin: 20px 0; font-weight: bold; }
        .credentials { background: #f8fafc; border: 2px dashed #cbd5e1; padding: 16px; margin: 16px 0; border-radius: 8px; }
        .credentials .label { font-size: 11px; font-weight: 600; text-transform: uppercase; color: #64748b; letter-spacing: 1px; }
        .credentials .value { font-family: 'Courier New', monospace; font-size: 15px; font-weight: bold; color: #0f172a; margin-top: 2px; }
        .warning { background-color: #fef3c7; border-left: 4px solid #f59e0b; padding: 14px 16px; margin: 20px 0; border-radius: 4px; font-size: 13px; }
        .footer { background-color: #eff6ff; padding: 18px; text-align: center; font-size: 12px; color: #475569; border-radius: 0 0 8px 8px; border-top: 2px solid #dbeafe; }
    </style>
</head>
<body>
    <div class="header">
        @include('emails.partials.logo')
        <h1 style="margin:0">{{ $cabinetName }}</h1>
        <p style="margin:6px 0 0;opacity:.9">Vous êtes invité à rejoindre l'équipe</p>
    </div>

    <div class="content">
        <p>Bonjour <strong>{{ $member->name }}</strong>,</p>

        <p><strong>{{ $invitedByName }}</strong> vous invite à rejoindre l'espace de travail de <strong>{{ $cabinetName }}</strong> en tant que <strong>{{ $roleLabel }}</strong>.</p>

        <p>Pour activer votre accès, choisissez votre mot de passe :</p>

        <p style="text-align:center;margin:24px 0">
            <a href="{{ $actionUrl }}" class="button">Activer mon compte</a>
        </p>

        <p style="font-size:13px;color:#64748b;text-align:center;word-break:break-all">
            Ou copiez ce lien dans votre navigateur :<br>
            <span style="color:{{ $brand['color'] }}">{{ $actionUrl }}</span>
        </p>

        <div class="credentials">
            <div class="label">Votre identifiant de connexion</div>
            <div class="value">{{ $member->email }}</div>
        </div>

        <div class="warning">
            <strong>Important :</strong> ce lien expire dans <strong>7 jours</strong> et ne fonctionne qu'une fois. Passé ce délai, demandez une nouvelle invitation à {{ $invitedByName }}.
        </div>

        <p style="font-size:13px;color:#64748b">Ensuite, connectez-vous ici : <a href="{{ $loginUrl }}" style="color:{{ $brand['color'] }}">{{ $loginUrl }}</a></p>
    </div>

    <div class="footer">
        {{ $cabinetName }}<br>
        Si vous n'attendiez pas cette invitation, ignorez simplement ce courriel.
    </div>
</body>
</html>
