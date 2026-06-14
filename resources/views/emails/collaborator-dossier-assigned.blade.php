<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Nouveau dossier assigné — Volonté Canada</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 620px; margin: 0 auto; padding: 20px; background-color: #fafafa; }
        .header { background: linear-gradient(135deg, #059669 0%, #047857 100%); color: white; padding: 28px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background-color: #ffffff; padding: 30px; border: 1px solid #e5e7eb; }
        .button { display: inline-block; background: linear-gradient(135deg, #059669 0%, #047857 100%); color: #ffffff !important; padding: 14px 32px; text-decoration: none; border-radius: 8px; margin: 20px 0; font-weight: bold; box-shadow: 0 4px 12px rgba(5, 150, 105, 0.25); }
        .dossier-card { background: #f0fdf4; border: 1px solid #bbf7d0; padding: 18px; border-radius: 8px; margin: 18px 0; }
        .row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #dcfce7; }
        .row:last-child { border-bottom: none; }
        .row .k { font-size: 12px; text-transform: uppercase; font-weight: 600; color: #047857; letter-spacing: .5px; }
        .row .v { font-size: 14px; color: #14532d; font-weight: 500; text-align: right; }
        .footer { background-color: #f0fdf4; padding: 18px; text-align: center; font-size: 12px; color: #475569; border-radius: 0 0 8px 8px; border-top: 2px solid #dcfce7; }
    </style>
</head>
<body>
    <div class="header">
        <h1 style="margin:0">📂 Nouveau dossier</h1>
        <p style="margin:6px 0 0;opacity:.9">Vous venez d'être assigné</p>
    </div>

    <div class="content">
        <p>Bonjour <strong>{{ $collaborator->first_name }}</strong>,</p>

        <p>L'administrateur vient de vous assigner un nouveau dossier sur la plateforme Volonté Canada. Voici les détails :</p>

        <div class="dossier-card">
            <div class="row">
                <span class="k">Dossier</span>
                <span class="v">{{ $dossier->name }}</span>
            </div>
            @if($dossier->service_name)
            <div class="row">
                <span class="k">Service</span>
                <span class="v">{{ $dossier->service_name }}</span>
            </div>
            @endif
            <div class="row">
                <span class="k">Client</span>
                <span class="v">{{ $clientName }}</span>
            </div>
            @if($dossier->deadline_at)
            <div class="row">
                <span class="k">Échéance</span>
                <span class="v">{{ \Carbon\Carbon::parse($dossier->deadline_at)->format('d/m/Y') }}</span>
            </div>
            @endif
        </div>

        <p>Connectez-vous à votre espace pour consulter les documents de base à remplir et le suivi des invitations envoyées au client.</p>

        <p style="text-align:center;margin:24px 0">
            <a href="{{ $dossierUrl }}" class="button">Ouvrir le dossier</a>
        </p>

        <p style="font-size:13px;color:#64748b;text-align:center;word-break:break-all">
            Ou copiez ce lien dans votre navigateur :<br>
            <span style="color:#059669">{{ $dossierUrl }}</span>
        </p>
    </div>

    <div class="footer">
        Volonté Canada — Cabinet d'immigration<br>
        Cet email vous a été envoyé automatiquement, merci de ne pas y répondre.
    </div>
</body>
</html>
