<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>{{ $isCancelled ? 'Rendez-vous annulé' : ($isReminder ? 'Rappel de rendez-vous' : 'Rendez-vous confirmé') }} — {{ $cabinetName }}</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 620px; margin: 0 auto; padding: 20px; background-color: #fafafa; }
        .header { background: linear-gradient(135deg, {{ $brand['color'] }} 0%, {{ $brand['color_dark'] }} 100%); color: white; padding: 24px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background-color: #ffffff; padding: 26px; border: 1px solid #e5e7eb; }
        .card { background: #eff6ff; border-left: 4px solid {{ $brand['color'] }}; padding: 14px 16px; margin: 16px 0; border-radius: 4px; }
        .card.cancelled { background: #fef2f2; border-left-color: {{ $brand['color'] }}; }
        .button { display: inline-block; background: {{ $brand['color'] }}; color: #ffffff !important; padding: 12px 26px; text-decoration: none; border-radius: 8px; font-weight: bold; }
        .footer { background-color: #eff6ff; padding: 16px; text-align: center; font-size: 12px; color: #475569; border-radius: 0 0 8px 8px; border-top: 2px solid #dbeafe; }
    </style>
</head>
<body>
    <div class="header">
        @include('emails.partials.logo')
        <h1 style="margin:0;font-size:22px">{{ $cabinetName }}</h1>
        <p style="margin:6px 0 0;opacity:.9">{{ $isCancelled ? 'Rendez-vous annulé' : ($isReminder ? 'Rappel de rendez-vous' : 'Rendez-vous confirmé') }}</p>
    </div>

    <div class="content">
        <p>Bonjour <strong>{{ $appointment->name }}</strong>,</p>

        <div class="card {{ $isCancelled ? 'cancelled' : '' }}">
            <strong>{{ $serviceName }}</strong><br>
            {{ ucfirst($when) }} <span style="color:#64748b;font-size:12px">({{ $timezone }})</span><br>
            @if (! $isCancelled)
                {{ $modeLabel }}
                @if ($appointment->meeting_url)
                    — <a href="{{ $appointment->meeting_url }}">rejoindre la rencontre</a>
                @elseif ($location)
                    — {{ $location }}
                @endif
            @endif
        </div>

        @if ($isCancelled)
            <p>Ce rendez-vous est annulé. Vous pouvez en réserver un autre à tout moment.</p>
        @else
            @if ($isReminder)
            <p>Nous vous rappelons votre rendez-vous ci-dessus.
            @else
            <p>L’invitation jointe (fichier .ics) l’ajoute à votre agenda en un clic.
            @endif
                @if ($appointment->meeting_mode === 'video' && ! $appointment->meeting_url)
                    Le lien de la visioconférence vous sera transmis par votre conseiller.
                @endif
            </p>
            <p style="text-align:center;margin:24px 0 8px">
                <a href="{{ $manageUrl }}" class="button">Voir ou annuler le rendez-vous</a>
            </p>
        @endif
    </div>

    <div class="footer">
        {{ $cabinetName }}<br>
        Ce courriel vous a été envoyé automatiquement à la suite de votre réservation.
    </div>
</body>
</html>
