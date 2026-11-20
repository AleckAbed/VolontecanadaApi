<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $isCancelled ? 'Rendez-vous annulé' : ($isReminder ? 'Rappel de rendez-vous' : ($isRescheduled ? 'Rendez-vous déplacé' : 'Rendez-vous confirmé')) }} — {{ $cabinetName }}</title>
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
        <p style="margin:6px 0 0;opacity:.9">{{ __('emails.appointment.title_' . $kind) }}</p>
    </div>

    <div class="content">
        <p>{!! __('emails.hello', ['name' => '<strong>' . e($appointment->name) . '</strong>']) !!}</p>

        <div class="card {{ $isCancelled ? 'cancelled' : '' }}">
            <strong>{{ $serviceName }}</strong><br>
            {{ \Illuminate\Support\Str::ucfirst($when) }} <span style="color:#64748b;font-size:12px">({{ $timezone }})</span><br>
            @if (! $isCancelled)
                {{ $modeLabel }}
                @if ($appointment->meeting_url)
                    — <a href="{{ $appointment->meeting_url }}">{{ __('emails.appointment.join') }}</a>
                @elseif ($location)
                    — {{ $location }}
                @endif
            @endif
        </div>

        @if ($isCancelled)
            <p>{{ __('emails.appointment.cancelled_text') }}</p>
        @else
            <p>
                @if ($isReminder){{ __('emails.appointment.reminder_text') }}@elseif ($isRescheduled){{ __('emails.appointment.rescheduled_text') }}@else{{ __('emails.appointment.confirmed_text') }}@endif
                @if ($appointment->meeting_mode === 'video' && ! $appointment->meeting_url) {{ __('emails.appointment.video_later') }} @endif
            </p>
            <p style="text-align:center;margin:24px 0 8px">
                <a href="{{ $manageUrl }}" class="button">{{ __('emails.appointment.button') }}</a>
            </p>
        @endif
    </div>

    <div class="footer">
        {{ $cabinetName }}<br>
        {{ __('emails.appointment.footer') }}
    </div>
</body>
</html>
