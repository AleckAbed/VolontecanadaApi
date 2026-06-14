<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Invitation Volonté Canada</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 620px; margin: 0 auto; padding: 20px; background-color: #fafafa; }
        .header { background: linear-gradient(135deg, #b91c1c 0%, #991b1b 100%); color: white; padding: 28px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background-color: #ffffff; padding: 30px; border: 1px solid #e5e7eb; }
        .button { display: inline-block; background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%); color: #ffffff !important; padding: 14px 32px; text-decoration: none; border-radius: 8px; margin: 20px 0; font-weight: bold; box-shadow: 0 4px 12px rgba(185, 28, 28, 0.25); }
        .item-list { margin: 20px 0; padding: 0; list-style: none; }
        .item-list li { background: #fff; border: 1px solid #e5e7eb; padding: 12px 16px; border-radius: 6px; margin-bottom: 8px; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
        .badge-form { background: #fee2e2; color: #991b1b; }
        .badge-doc { background: #fff7ed; color: #9a3412; }
        .warning { background-color: #fef3c7; border-left: 4px solid #f59e0b; padding: 14px 16px; margin: 20px 0; border-radius: 4px; }
        .footer { background-color: #fef2f2; padding: 18px; text-align: center; font-size: 12px; color: #6b7280; border-radius: 0 0 8px 8px; border-top: 2px solid #fee2e2; }
        .msg { background: #fff; border-left: 3px solid #b91c1c; padding: 12px 16px; margin: 18px 0; font-style: italic; }
        .code-box { background: #fff7f7; border: 2px dashed #b91c1c; padding: 16px; margin: 14px 0; text-align: center; font-family: 'Courier New', monospace; font-size: 14px; font-weight: bold; color: #991b1b; border-radius: 8px; word-break: break-all; letter-spacing: 0.5px; }
        .code-label { font-size: 12px; color: #b91c1c; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; display: block; }
        .info { background: #fef2f2; border-left: 4px solid #b91c1c; padding: 14px 16px; margin: 18px 0; border-radius: 4px; font-size: 14px; }
    </style>
</head>
<body>
    <div class="header">
        <h1 style="margin:0">Volonté Canada</h1>
        <p style="margin:6px 0 0;opacity:.9">Cabinet d'immigration</p>
    </div>

    <div class="content">
        <p>Bonjour <strong>{{ $clientName }}</strong>,</p>
        <p>Votre conseiller vous invite à compléter les éléments suivants :</p>

        @if($customMessage)
            <div class="msg">{{ $customMessage }}</div>
        @endif

        <ul class="item-list">
            @foreach($items as $item)
                <li>
                    @if($item->item_kind === 'form')
                        <span class="badge badge-form">Formulaire</span>
                        <strong>{{ $item->formType?->name ?? '—' }}</strong>
                    @else
                        <span class="badge badge-doc">Document</span>
                        <strong>{{ $item->documentTemplate?->name ?? '—' }}</strong>
                    @endif
                </li>
            @endforeach
        </ul>

        <p>Vous pouvez les remplir à votre rythme — vos réponses sont sauvegardées automatiquement.</p>

        <div class="info">
            <strong>Comment accéder à votre invitation :</strong>
            <ol style="margin:8px 0 0;padding-left:20px">
                <li>Cliquez sur le bouton ci-dessous</li>
                <li>Entrez votre <strong>adresse courriel</strong> ({{ $invitation->email }})</li>
                <li>Saisissez le <strong>code d'accès</strong> indiqué plus bas</li>
            </ol>
        </div>

        <div style="text-align: center;">
            <a href="{{ $invitationUrl }}" class="button">Accéder à mon invitation</a>
        </div>

        <p style="margin-top:24px"><span class="code-label">Votre code d'accès unique</span></p>
        <div class="code-box">
            {{ $invitation->unique_code }}
        </div>
        <p style="font-size:13px;color:#6b7280;text-align:center;margin-top:8px">
            Copiez ce code et collez-le dans le champ "Code d'accès" de la page.
            <br>Pour votre sécurité, ce code n'est pas pré-rempli dans le lien.
        </p>

        <div class="warning">
            ⏱ Cette invitation expire le <strong>{{ $expiresAt }}</strong>. Assurez-vous de tout soumettre avant cette date.
        </div>
    </div>

    <div class="footer">
        <p>Email automatique — merci de ne pas répondre.<br>
        Si vous n'attendiez pas cette invitation, veuillez l'ignorer.</p>
    </div>
</body>
</html>
