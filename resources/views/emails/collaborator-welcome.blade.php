<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Activez votre compte — Volonté Canada</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 620px; margin: 0 auto; padding: 20px; background-color: #fafafa; }
        .header { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: white; padding: 28px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background-color: #ffffff; padding: 30px; border: 1px solid #e5e7eb; }
        .button { display: inline-block; background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: #ffffff !important; padding: 14px 32px; text-decoration: none; border-radius: 8px; margin: 20px 0; font-weight: bold; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25); }
        .info-card { background: #eff6ff; border-left: 4px solid #2563eb; padding: 14px 16px; margin: 18px 0; border-radius: 4px; font-size: 14px; }
        .info-card strong { color: #1e40af; }
        .credentials { background: #f8fafc; border: 2px dashed #cbd5e1; padding: 16px; margin: 16px 0; border-radius: 8px; }
        .credentials .label { font-size: 11px; font-weight: 600; text-transform: uppercase; color: #64748b; letter-spacing: 1px; }
        .credentials .value { font-family: 'Courier New', monospace; font-size: 15px; font-weight: bold; color: #0f172a; margin-top: 2px; }
        .warning { background-color: #fef3c7; border-left: 4px solid #f59e0b; padding: 14px 16px; margin: 20px 0; border-radius: 4px; font-size: 13px; }
        .footer { background-color: #eff6ff; padding: 18px; text-align: center; font-size: 12px; color: #475569; border-radius: 0 0 8px 8px; border-top: 2px solid #dbeafe; }
        .checklist { background: #f1f5f9; padding: 12px 16px; border-radius: 8px; margin: 14px 0; font-size: 13px; }
        .checklist li { margin: 4px 0; }
    </style>
</head>
<body>
    <div class="header">
        <h1 style="margin:0">Volonté Canada</h1>
        <p style="margin:6px 0 0;opacity:.9">Activez votre espace collaborateur</p>
    </div>

    <div class="content">
        <p>Bonjour <strong>{{ $collaborator->first_name }} {{ $collaborator->last_name }}</strong>,</p>

        <p>Un compte collaborateur a été créé pour vous sur la plateforme Volonté Canada. Pour activer votre accès, vous devez définir votre propre mot de passe en cliquant sur le bouton ci-dessous.</p>

        <div class="credentials">
            <div class="label">Votre identifiant de connexion</div>
            <div class="value">{{ $collaborator->email }}</div>
        </div>

        <p style="text-align:center;margin:24px 0">
            <a href="{{ $activationUrl }}" class="button">Activer mon compte</a>
        </p>

        <p style="font-size:13px;color:#64748b;text-align:center;word-break:break-all">
            Ou copiez ce lien dans votre navigateur :<br>
            <span style="color:#2563eb">{{ $activationUrl }}</span>
        </p>

        <div class="info-card">
            <strong>Règles de sécurité du mot de passe :</strong>
            <ul class="checklist" style="margin:8px 0 0;padding-left:20px">
                <li>Au moins <strong>8 caractères</strong></li>
                <li>Au moins une <strong>majuscule</strong> (A-Z)</li>
                <li>Au moins une <strong>minuscule</strong> (a-z)</li>
                <li>Au moins un <strong>chiffre</strong> (0-9)</li>
                <li>Au moins un <strong>caractère spécial</strong> (!@#$%^&*…)</li>
            </ul>
        </div>

        <div class="warning">
            <strong>⏱ Important :</strong> ce lien d'activation expire dans <strong>7 jours</strong>. Si vous n'avez pas activé votre compte avant, contactez votre administrateur pour en recevoir un nouveau.
        </div>
    </div>

    <div class="footer">
        Volonté Canada — Cabinet d'immigration<br>
        Cet email vous a été envoyé automatiquement, merci de ne pas y répondre.
    </div>
</body>
</html>
