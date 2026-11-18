<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Code de connexion — {{ $brand['name'] }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 560px; margin: 0 auto; padding: 20px;">
    @include('emails.partials.logo')
    <h1 style="font-size: 20px; color: {{ $brand['color_dark'] }};">{{ $brand['name'] }}</h1>
    <p>Bonjour {{ $name }},</p>
    <p>Voici votre code de connexion :</p>
    <p style="font-size: 32px; font-weight: bold; letter-spacing: 6px; text-align: center; background: #f1f5f9; padding: 14px; border-radius: 8px;">{{ $code }}</p>
    <p>Il est valable 10 minutes. Si vous n’essayez pas de vous connecter, ignorez ce courriel et changez votre mot de passe : quelqu’un le connaît.</p>
    <p style="font-size:12px;color:#64748b">Ne communiquez jamais ce code, même au cabinet ou au support.</p>
</body>
</html>
