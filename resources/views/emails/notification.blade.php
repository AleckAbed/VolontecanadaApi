<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 560px; margin: 0 auto; padding: 20px;">
    @include('emails.partials.logo')
    <p style="font-size:13px;color:#64748b;margin:0">{{ $brand['name'] }}</p>
    <h1 style="font-size: 19px; color: {{ $brand['color_dark'] }}; margin: 6px 0 12px;">{{ $title }}</h1>
    @if ($body)
        <p>{!! nl2br(e($body)) !!}</p>
    @endif
    <p style="margin:22px 0">
        <a href="{{ $url }}" style="display:inline-block;background:{{ $brand['color'] }};color:#ffffff;padding:11px 22px;border-radius:8px;text-decoration:none;font-weight:bold">Ouvrir dans la plateforme</a>
    </p>
    <p style="font-size:12px;color:#64748b">
        Vous recevez ce courriel selon vos préférences de notification.
        <a href="{{ $preferencesUrl }}" style="color:#64748b">Les modifier</a>
    </p>
</body>
</html>
