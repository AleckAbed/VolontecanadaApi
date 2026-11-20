<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('emails.portal.' . $mode . '_title') }} — {{ $cabinetName }}</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 620px; margin: 0 auto; padding: 20px; background-color: #fafafa; }
        .header { background: linear-gradient(135deg, {{ $brand['color'] }} 0%, {{ $brand['color_dark'] }} 100%); color: white; padding: 28px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background-color: #ffffff; padding: 30px; border: 1px solid #e5e7eb; }
        .button { display: inline-block; background: linear-gradient(135deg, {{ $brand['color'] }} 0%, {{ $brand['color_dark'] }} 100%); color: #ffffff !important; padding: 14px 32px; text-decoration: none; border-radius: 8px; margin: 20px 0; font-weight: bold; }
        .info { background: #eff6ff; border-left: 4px solid {{ $brand['color'] }}; padding: 14px 16px; margin: 18px 0; border-radius: 4px; font-size: 14px; }
        .warning { background-color: #fef3c7; border-left: 4px solid #f59e0b; padding: 14px 16px; margin: 20px 0; border-radius: 4px; font-size: 13px; }
        .footer { background-color: #eff6ff; padding: 18px; text-align: center; font-size: 12px; color: #475569; border-radius: 0 0 8px 8px; border-top: 2px solid #dbeafe; }
    </style>
</head>
<body>
    <div class="header">
        @include('emails.partials.logo')
        <h1 style="margin:0">{{ $cabinetName }}</h1>
        <p style="margin:6px 0 0;opacity:.9">{{ __('emails.portal.' . $mode . '_title') }}</p>
    </div>

    <div class="content">
        <p>{!! __('emails.hello', ['name' => '<strong>' . e($client->first_name . ' ' . $client->last_name) . '</strong>']) !!}</p>

        @if ($mode === 'invite')
            <p>{{ __('emails.portal.invite_intro', ['cabinet' => $cabinetName]) }}</p>
            <div class="info">{{ __('emails.portal.invite_info') }}</div>
            <p>{{ __('emails.portal.invite_cta') }}</p>
        @elseif ($mode === 'reminder')
            <p>{{ __('emails.portal.reminder_intro', ['cabinet' => $cabinetName]) }}</p>
        @else
            <p>{{ __('emails.portal.reset_intro') }}</p>
        @endif

        <p style="text-align:center;margin:24px 0">
            <a href="{{ $actionUrl }}" class="button">{{ __('emails.portal.' . $mode . '_button') }}</a>
        </p>

        <p style="font-size:13px;color:#64748b;text-align:center;word-break:break-all">
            {{ __('emails.copy_link') }}<br>
            <a href="{{ $actionUrl }}" style="color:{{ $brand['color'] }}">{{ $actionUrl }}</a>
        </p>

        @if ($mode === 'reminder')
            <p style="font-size:13px;color:#64748b">{{ __('emails.portal.reminder_note') }}</p>
        @else
            <div class="warning">
                <strong>{{ __('emails.important') }}</strong> {{ __('emails.portal.' . $mode . '_expires') }}
            </div>
            <p style="font-size:13px;color:#64748b">{{ __('emails.portal.then_login') }} <a href="{{ $loginUrl }}" style="color:{{ $brand['color'] }}">{{ $loginUrl }}</a></p>
        @endif
    </div>

    <div class="footer">
        {{ $cabinetName }}<br>
        @if ($mode === 'reset'){{ __('emails.ignore_if_not_you') }}@else{{ __('emails.auto_no_reply') }}@endif
    </div>
</body>
</html>
