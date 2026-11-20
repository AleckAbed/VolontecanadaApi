<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document à remplir</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f4f4f4; }
        .container { max-width: 600px; margin: 30px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,.1); }
        .header { background: #1a2e4a; color: #fff; padding: 30px; text-align: center; }
        .header h1 { margin: 0; font-size: 22px; }
        .header p { margin: 5px 0 0; opacity: .8; font-size: 14px; }
        .body { padding: 30px; }
        .body p { margin: 0 0 15px; }
        .message-box { background: #f0f7ff; border-left: 4px solid #1a2e4a; padding: 15px; border-radius: 4px; margin: 20px 0; font-style: italic; }
        .btn { display: inline-block; padding: 14px 32px; background: #1a2e4a; color: #fff; text-decoration: none; border-radius: 6px; font-size: 16px; font-weight: bold; margin: 20px 0; }
        .info { background: #fff8e1; border: 1px solid #ffe082; border-radius: 6px; padding: 15px; margin: 20px 0; font-size: 14px; }
        .footer { background: #f4f4f4; padding: 20px; text-align: center; font-size: 12px; color: #888; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            @include('emails.partials.logo')
            <h1>{{ $brand['name'] }}</h1>
            <p>{{ __('emails.immigration_firm') }}</p>
        </div>
        <div class="body">
            <p>{!! __('emails.hello', ['name' => '<strong>' . e($clientName) . '</strong>']) !!}</p>
            <p>{{ __('emails.document.intro', ['cabinet' => $brand['name']]) }}</p>
            <p><strong>{{ $documentName }}</strong></p>

            @if($personalMessage)
            <div class="message-box">
                <strong>{{ __('emails.document.advisor_message') }}</strong><br>
                {{ $personalMessage }}
            </div>
            @endif

            <p>{{ __('emails.document.cta') }}</p>

            <div style="text-align: center;">
                <a href="{{ $fillUrl }}" class="btn">{{ __('emails.document.button') }}</a>
            </div>

            <div class="info">
                <strong>{{ __('emails.important') }}</strong> {{ __('emails.document.personal') }}
                @if($expiresAt) {{ __('emails.document.expires', ['date' => $expiresAt]) }} @endif
            </div>

            <p>{{ __('emails.copy_link') }}</p>
            <p style="word-break: break-all; font-size: 13px;"><a href="{{ $fillUrl }}" style="color:#555">{{ $fillUrl }}</a></p>

            <p>{{ __('emails.questions_advisor') }}</p>
            <p>{{ __('emails.document.regards') }}<br><strong>{{ __('emails.document.team', ['cabinet' => $brand['name']]) }}</strong></p>
        </div>
        <div class="footer">
            <p>© {{ date('Y') }} {{ $brand['name'] }}</p>
            <p>{{ __('emails.auto_no_reply') }}</p>
        </div>
    </div>
</body>
</html>
