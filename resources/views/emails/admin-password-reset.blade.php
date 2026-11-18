<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>{{ $isInvite ? 'Activez votre compte' : 'Réinitialisation du mot de passe' }} — {{ $cabinetName }}</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 620px; margin: 0 auto; padding: 20px; background-color: #fafafa; }
        .header { background: linear-gradient(135deg, {{ $brand['color'] }} 0%, {{ $brand['color_dark'] }} 100%); color: white; padding: 28px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background-color: #ffffff; padding: 30px; border: 1px solid #e5e7eb; }
        .button { display: inline-block; background: linear-gradient(135deg, {{ $brand['color'] }} 0%, {{ $brand['color_dark'] }} 100%); color: #ffffff !important; padding: 14px 32px; text-decoration: none; border-radius: 8px; margin: 20px 0; font-weight: bold; }
        .warning { background-color: #fef3c7; border-left: 4px solid #f59e0b; padding: 14px 16px; margin: 20px 0; border-radius: 4px; font-size: 13px; }
        .footer { background-color: #eff6ff; padding: 18px; text-align: center; font-size: 12px; color: #475569; border-radius: 0 0 8px 8px; border-top: 2px solid #dbeafe; }
    </style>
</head>
<body>
    <div class="header">
        @include('emails.partials.logo')
        <h1 style="margin:0">{{ $cabinetName }}</h1>
        <p style="margin:6px 0 0;opacity:.9">{{ $isInvite ? 'Activez votre compte' : 'Réinitialisation du mot de passe' }}</p>
    </div>

    <div class="content">
        <p>Bonjour <strong>{{ $admin->name }}</strong>,</p>

        @if ($isInvite)
            <p>Votre compte n'est pas encore activé. Choisissez votre mot de passe pour accéder à l'espace de <strong>{{ $cabinetName }}</strong> :</p>
        @else
            <p>Nous avons reçu une demande de réinitialisation du mot de passe de votre compte. Pour en choisir un nouveau :</p>
        @endif

        <p style="text-align:center;margin:24px 0">
            <a href="{{ $actionUrl }}" class="button">{{ $isInvite ? 'Activer mon compte' : 'Choisir un nouveau mot de passe' }}</a>
        </p>

        <p style="font-size:13px;color:#64748b;text-align:center;word-break:break-all">
            Ou copiez ce lien dans votre navigateur :<br>
            <span style="color:{{ $brand['color'] }}">{{ $actionUrl }}</span>
        </p>

        <div class="warning">
            <strong>Important :</strong> ce lien expire dans <strong>{{ $isInvite ? '7 jours' : '1 heure' }}</strong> et ne fonctionne qu'une fois.
            @unless ($isInvite)
                Toutes vos sessions ouvertes seront fermées une fois le mot de passe changé.
            @endunless
        </div>

        <p style="font-size:13px;color:#64748b">Si vous n'êtes pas à l'origine de cette demande, ignorez ce courriel : votre mot de passe actuel reste valable.</p>
    </div>

    <div class="footer">
        {{ $cabinetName }}<br>
        Cet email vous a été envoyé automatiquement, merci de ne pas y répondre.
    </div>
</body>
</html>
