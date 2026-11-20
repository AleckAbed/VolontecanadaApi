<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <title>Invitation {{ $brand['name'] }}</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 620px; margin: 0 auto; padding: 20px; background-color: #fafafa; }
        .header { background: linear-gradient(135deg, {{ $brand['color'] }} 0%, {{ $brand['color_dark'] }} 100%); color: white; padding: 28px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background-color: #ffffff; padding: 30px; border: 1px solid #e5e7eb; }
        .button { display: inline-block; background: linear-gradient(135deg, {{ $brand['color'] }} 0%, {{ $brand['color'] }} 100%); color: #ffffff !important; padding: 14px 32px; text-decoration: none; border-radius: 8px; margin: 20px 0; font-weight: bold; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15); }
        .item-list { margin: 20px 0; padding: 0; list-style: none; }
        .item-list li { background: #fff; border: 1px solid #e5e7eb; padding: 12px 16px; border-radius: 6px; margin-bottom: 8px; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
        .badge-form { background: #fee2e2; color: {{ $brand['color_dark'] }}; }
        .badge-doc { background: #fff7ed; color: #9a3412; }
        .warning { background-color: #fef3c7; border-left: 4px solid #f59e0b; padding: 14px 16px; margin: 20px 0; border-radius: 4px; }
        .footer { background-color: #fef2f2; padding: 18px; text-align: center; font-size: 12px; color: #6b7280; border-radius: 0 0 8px 8px; border-top: 2px solid #fee2e2; }
        .msg { background: #fff; border-left: 3px solid {{ $brand['color'] }}; padding: 12px 16px; margin: 18px 0; font-style: italic; }
        .code-box { background: #fff7f7; border: 2px dashed {{ $brand['color'] }}; padding: 16px; margin: 14px 0; text-align: center; font-family: 'Courier New', monospace; font-size: 14px; font-weight: bold; color: {{ $brand['color_dark'] }}; border-radius: 8px; word-break: break-all; letter-spacing: 0.5px; }
        .code-label { font-size: 12px; color: {{ $brand['color'] }}; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; display: block; }
        .info { background: #fef2f2; border-left: 4px solid {{ $brand['color'] }}; padding: 14px 16px; margin: 18px 0; border-radius: 4px; font-size: 14px; }
    </style>
</head>
<body>
    <div class="header">
        @include('emails.partials.logo')
        <h1 style="margin:0">{{ $brand['name'] }}</h1>
        <p style="margin:6px 0 0;opacity:.9">{{ __('emails.immigration_firm') }}</p>
    </div>

    <div class="content">
        <p>{!! __('emails.hello', ['name' => '<strong>' . e($clientName) . '</strong>']) !!}</p>
        <p>{{ __('emails.envoi.intro') }}</p>

        @if($customMessage)
            <div class="msg">{{ $customMessage }}</div>
        @endif

        <ul class="item-list">
            @foreach($items as $item)
                <li>
                    @if($item->item_kind === 'form')
                        <span class="badge badge-form">{{ __('emails.envoi.form') }}</span>
                        <strong>{{ $item->formType?->name ?? '—' }}</strong>
                    @else
                        <span class="badge badge-doc">{{ __('emails.envoi.document') }}</span>
                        <strong>{{ $item->documentTemplate?->name ?? '—' }}</strong>
                    @endif
                </li>
            @endforeach
        </ul>

        <p>{{ __('emails.envoi.pace') }}</p>

        <div class="info">
            <strong>{{ __('emails.envoi.howto_title') }}</strong>
            <ol style="margin:8px 0 0;padding-left:20px">
                <li>{{ __('emails.envoi.step1') }}</li>
                <li>{{ __('emails.envoi.step2', ['email' => $invitation->email]) }}</li>
                <li>{{ __('emails.envoi.step3') }}</li>
            </ol>
        </div>

        <div style="text-align: center;">
            <a href="{{ $invitationUrl }}" class="button">{{ __('emails.envoi.button') }}</a>
        </div>

        <p style="margin-top:24px"><span class="code-label">{{ __('emails.envoi.code_label') }}</span></p>
        <div class="code-box">
            {{ $invitation->unique_code }}
        </div>
        <p style="font-size:13px;color:#6b7280;text-align:center;margin-top:8px">{{ __('emails.envoi.code_help') }}</p>

        <div class="warning">
            {{ __('emails.envoi.expires', ['date' => $expiresAt]) }}
        </div>
    </div>

    <div class="footer">
        <p>{{ __('emails.auto_no_reply') }}<br>{{ __('emails.envoi.not_expected') }}</p>
    </div>
</body>
</html>
