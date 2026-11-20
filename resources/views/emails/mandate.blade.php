<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $mandate->title }} — {{ $cabinetName }}</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 620px; margin: 0 auto; padding: 20px; background-color: #fafafa; }
        .header { background: linear-gradient(135deg, {{ $brand['color'] }} 0%, {{ $brand['color_dark'] }} 100%); color: white; padding: 24px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background-color: #ffffff; padding: 26px; border: 1px solid #e5e7eb; }
        .signed { background: #ecfdf5; border-left: 4px solid #10b981; padding: 12px 16px; border-radius: 4px; margin: 16px 0; }
        .body { white-space: pre-line; background: #f8fafc; border: 1px solid #e2e8f0; padding: 14px; border-radius: 6px; font-size: 14px; }
        .button { display: inline-block; background: {{ $brand['color'] }}; color: #ffffff !important; padding: 13px 30px; text-decoration: none; border-radius: 8px; font-weight: bold; }
        .footer { background-color: #eff6ff; padding: 16px; text-align: center; font-size: 12px; color: #475569; border-radius: 0 0 8px 8px; border-top: 2px solid #dbeafe; }
    </style>
</head>
<body>
    <div class="header">
        @include('emails.partials.logo')
        <h1 style="margin:0;font-size:22px">{{ $cabinetName }}</h1>
        <p style="margin:6px 0 0;opacity:.9">{{ $mandate->title }}</p>
    </div>

    <div class="content">
        <p>{!! __('emails.hello', ['name' => '<strong>' . e($clientName) . '</strong>']) !!}</p>

        @if ($signedCopy)
            <div class="signed">{{ __('emails.mandate.signed', ['date' => $mandate->signed_at?->format('Y-m-d H:i'), 'name' => $mandate->signed_name]) }}</div>
            <div class="body">{{ $mandate->body }}</div>
            <p style="font-size:12px;color:#64748b;word-break:break-all">{{ __('emails.mandate.hash') }} {{ $mandate->body_hash }}</p>
        @else
            <p>{{ __('emails.mandate.intro', ['cabinet' => $cabinetName]) }}</p>
            <p style="text-align:center;margin:24px 0 8px">
                <a href="{{ $url }}" class="button">{{ __('emails.mandate.button') }}</a>
            </p>
        @endif
    </div>

    <div class="footer">
        {{ $cabinetName }}<br>
        {{ __('emails.mandate.footer') }}
    </div>
</body>
</html>
