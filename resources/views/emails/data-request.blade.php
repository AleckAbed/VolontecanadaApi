<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <title>Votre demande de suppression de données — {{ $cabinetName }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 620px; margin: 0 auto; padding: 20px;">
    @include('emails.partials.logo')
    <h1 style="font-size: 20px; color: {{ $brand['color_dark'] }};">{{ $cabinetName }}</h1>
    <p>{{ __('emails.hello', ['name' => $name]) }}</p>

    @if ($completed)
        <p>{{ __('emails.data_request.completed1') }}</p>
        <p>{{ __('emails.data_request.completed2') }}</p>
    @else
        <p>{{ __('emails.data_request.refused') }}</p>
    @endif

    @if ($response)
        <p style="background:#f1f5f9;padding:12px 14px;border-radius:6px"><strong>{{ __('emails.data_request.cabinet_message') }}</strong><br>{!! nl2br(e($response)) !!}</p>
    @endif

    <p>{{ __('emails.data_request.questions') }}</p>
    <p style="font-size:12px;color:#64748b">{{ $cabinetName }}</p>
</body>
</html>
