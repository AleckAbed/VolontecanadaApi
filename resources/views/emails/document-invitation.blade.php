<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document à remplir</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f4f4f4; }
        .container { max-width: 600px; margin: 30px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,.1); }
        .header { background: #1a2e4a; color: #fff; padding: 30px; text-align: center; }
        .header h1 { margin: 0; font-size: 22px; }
        .header p { margin: 5px 0 0; opacity: .8; font-size: 14px; }
        .body { padding: 30px; }
        .body p { margin: 0 0 15px; }
        .message-box { background: #f0f7ff; border-left: 4px solid #1a2e4a; padding: 15px; border-radius: 4px; margin: 20px 0; font-style: italic; }
        .btn { display: inline-block; padding: 14px 32px; background: #1a2e4a; color: #fff; text-decoration: none; border-radius: 6px; font-size: 16px; font-weight: bold; margin: 20px 0; }
        .info { background: #fff8e1; border: 1px solid #ffe082; border-radius: 6px; padding: 15px; margin: 20px 0; font-size: 14px; }
        .footer { background: #f4f4f4; padding: 20px; text-align: center; font-size: 12px; color: #888; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Volonte Canada</h1>
            <p>Cabinet d'immigration</p>
        </div>
        <div class="body">
            <p>Bonjour <strong>{{ $clientName }}</strong>,</p>
            <p>Le cabinet Volonte Canada vous invite à remplir le document suivant dans le cadre de votre dossier d'immigration :</p>
            <p><strong>📄 {{ $documentName }}</strong></p>

            @if($personalMessage)
            <div class="message-box">
                <strong>Message de votre conseiller :</strong><br>
                {{ $personalMessage }}
            </div>
            @endif

            <p>Cliquez sur le bouton ci-dessous pour accéder au document et le remplir directement en ligne :</p>

            <div style="text-align: center;">
                <a href="{{ $fillUrl }}" class="btn">Remplir le document</a>
            </div>

            <div class="info">
                ⚠️ <strong>Important :</strong> Ce lien est personnel et confidentiel.
                @if($expiresAt)
                    Il expire le <strong>{{ $expiresAt }}</strong>.
                @endif
                Ne le partagez avec personne.
            </div>

            <p>Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :</p>
            <p style="word-break: break-all; font-size: 13px; color: #555;">{{ $fillUrl }}</p>

            <p>Pour toute question, contactez votre conseiller en immigration.</p>
            <p>Cordialement,<br><strong>L'équipe Volonte Canada</strong></p>
        </div>
        <div class="footer">
            <p>© {{ date('Y') }} Volonte Canada — Cabinet d'immigration</p>
            <p>Cet email a été envoyé automatiquement, merci de ne pas y répondre directement.</p>
        </div>
    </div>
</body>
</html>
