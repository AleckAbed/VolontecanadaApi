<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invitation au Formulaire</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background-color: #dc2626;
            color: white;
            padding: 20px;
            text-align: center;
            border-radius: 5px 5px 0 0;
        }
        .content {
            background-color: #f9fafb;
            padding: 30px;
            border: 1px solid #e5e7eb;
        }
        .button {
            display: inline-block;
            background-color: #dc2626;
            color: white;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 5px;
            margin: 20px 0;
            font-weight: bold;
        }
        .code-box {
            background-color: #fff;
            border: 2px solid #dc2626;
            padding: 15px;
            margin: 20px 0;
            text-align: center;
            font-family: monospace;
            font-size: 16px;
            font-weight: bold;
            border-radius: 5px;
        }
        .footer {
            background-color: #f3f4f6;
            padding: 20px;
            text-align: center;
            font-size: 12px;
            color: #6b7280;
            border-radius: 0 0 5px 5px;
        }
        .warning {
            background-color: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 15px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Formulaire d'Immigration</h1>
    </div>
    
    <div class="content">
        <p>Bonjour {{ $clientName }},</p>
        
        <p>Vous avez été invité à compléter un formulaire d'immigration. Veuillez utiliser le lien ci-dessous pour accéder au formulaire.</p>
        
        <div style="text-align: center;">
            <a href="{{ $verificationUrl }}" class="button">Accéder au Formulaire</a>
        </div>
        
        <p><strong>Code d'accès unique :</strong></p>
        <div class="code-box">
            {{ $uniqueCode }}
        </div>
        
        <p>Vous devrez utiliser ce code avec votre adresse email pour accéder au formulaire.</p>
        
        <div class="warning">
            <p><strong>⚠️ Important :</strong></p>
            <p>Ce formulaire expire le <strong>{{ $expiresAt }}</strong> (dans 14 jours).</p>
            <p>Assurez-vous de compléter le formulaire avant cette date.</p>
        </div>
        
        <p>Si vous avez des questions, n'hésitez pas à contacter votre consultant.</p>
        
        <p>Cordialement,<br>
        L'équipe du Cabinet d'Immigration</p>
    </div>
    
    <div class="footer">
        <p>Cet email a été envoyé automatiquement. Merci de ne pas y répondre.</p>
        <p>Si vous n'avez pas demandé ce formulaire, veuillez ignorer cet email.</p>
    </div>
</body>
</html>



