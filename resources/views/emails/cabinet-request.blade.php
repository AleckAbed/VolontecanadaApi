<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>{{ $appName }}</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 620px; margin: 0 auto; padding: 20px; background-color: #fafafa; }
        .header { background: linear-gradient(135deg, {{ $brand['color'] }} 0%, {{ $brand['color_dark'] }} 100%); color: white; padding: 28px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background-color: #ffffff; padding: 30px; border: 1px solid #e5e7eb; }
        .button { display: inline-block; background: linear-gradient(135deg, {{ $brand['color'] }} 0%, {{ $brand['color_dark'] }} 100%); color: #ffffff !important; padding: 14px 32px; text-decoration: none; border-radius: 8px; margin: 20px 0; font-weight: bold; }
        .box { background: #f8fafc; border: 1px solid #e2e8f0; padding: 14px 16px; margin: 16px 0; border-radius: 8px; font-size: 14px; }
        .box td { padding: 2px 8px 2px 0; vertical-align: top; }
        .warning { background-color: #fef3c7; border-left: 4px solid #f59e0b; padding: 14px 16px; margin: 20px 0; border-radius: 4px; font-size: 13px; }
        .footer { background-color: #eff6ff; padding: 18px; text-align: center; font-size: 12px; color: #475569; border-radius: 0 0 8px 8px; border-top: 2px solid #dbeafe; }
    </style>
</head>
<body>
    <div class="header">
        @include('emails.partials.logo')
        <h1 style="margin:0">{{ $kind === 'approved' ? $req->cabinet_name : $appName }}</h1>
    </div>

    <div class="content">
        @if ($kind === 'new')
            <p>Une nouvelle demande d'ouverture de cabinet attend votre décision.</p>
        @else
            <p>Bonjour <strong>{{ $req->contact_name }}</strong>,</p>
        @endif

        @if ($kind === 'received')
            <p>Nous avons bien reçu votre demande d'ouverture pour <strong>{{ $req->cabinet_name }}</strong>. Notre équipe l'étudie et vous répond généralement sous 2 jours ouvrables.</p>
            <p>Dès qu'elle est acceptée, vous recevrez un lien pour activer votre compte de propriétaire.</p>
        @elseif ($kind === 'approved')
            <p>Bonne nouvelle : votre cabinet <strong>{{ $req->cabinet_name }}</strong> est ouvert. Activez votre compte de propriétaire en choisissant votre mot de passe :</p>
            <p style="text-align:center;margin:24px 0"><a href="{{ $actionUrl }}" class="button">Activer mon compte</a></p>
            <p style="font-size:13px;color:#64748b;text-align:center;word-break:break-all">Ou copiez ce lien dans votre navigateur :<br><span style="color:{{ $brand['color'] }}">{{ $actionUrl }}</span></p>
            <div class="warning"><strong>Important :</strong> ce lien expire dans <strong>7 jours</strong> et ne fonctionne qu'une fois. Votre identifiant est <strong>{{ $req->email }}</strong>.</div>
        @elseif ($kind === 'declined')
            <p>Nous avons étudié votre demande d'ouverture pour <strong>{{ $req->cabinet_name }}</strong> et ne pouvons pas y donner suite pour le moment.</p>
            @if ($req->decline_reason)
                <div class="box"><strong>Motif :</strong><br>{!! nl2br(e($req->decline_reason)) !!}</div>
            @endif
            <p>Vous pouvez répondre à ce courriel pour toute question.</p>
        @endif

        @if (in_array($kind, ['new', 'received'], true))
            <div class="box">
                <table>
                    <tr><td><strong>Cabinet</strong></td><td>{{ $req->cabinet_name }}{{ $req->province ? ' (' . $req->province . ')' : '' }}</td></tr>
                    <tr><td><strong>Contact</strong></td><td>{{ $req->contact_name }} · {{ $req->email }}{{ $req->phone ? ' · ' . $req->phone : '' }}</td></tr>
                    @if ($req->team_size)<tr><td><strong>Équipe</strong></td><td>{{ $req->team_size }}</td></tr>@endif
                    @if ($req->license_number)<tr><td><strong>N° CICC</strong></td><td>{{ $req->license_number }}</td></tr>@endif
                    @if ($req->message)<tr><td><strong>Message</strong></td><td>{!! nl2br(e($req->message)) !!}</td></tr>@endif
                </table>
            </div>
        @endif

        @if ($kind === 'new')
            <p style="text-align:center;margin:24px 0"><a href="{{ $actionUrl }}" class="button">Étudier la demande</a></p>
        @endif
    </div>

    <div class="footer">
        {{ $appName }}<br>
        @if ($kind !== 'new')Si vous n'êtes pas à l'origine de cette demande, ignorez simplement ce courriel.@endif
    </div>
</body>
</html>
