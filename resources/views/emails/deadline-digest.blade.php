<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Échéances — {{ $cabinetName }}</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 620px; margin: 0 auto; padding: 20px; background-color: #fafafa; }
        .header { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: white; padding: 24px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background-color: #ffffff; padding: 26px; border: 1px solid #e5e7eb; }
        .item { border-left: 4px solid #f59e0b; background: #fffbeb; padding: 10px 14px; margin: 10px 0; border-radius: 4px; }
        .item.overdue { border-left-color: #dc2626; background: #fef2f2; }
        .item a { color: #1d4ed8; font-weight: bold; text-decoration: none; }
        .button { display: inline-block; background: #2563eb; color: #ffffff !important; padding: 12px 26px; text-decoration: none; border-radius: 8px; font-weight: bold; }
        .footer { background-color: #eff6ff; padding: 16px; text-align: center; font-size: 12px; color: #475569; border-radius: 0 0 8px 8px; border-top: 2px solid #dbeafe; }
    </style>
</head>
<body>
    <div class="header">
        <h1 style="margin:0;font-size:22px">{{ $cabinetName }}</h1>
        <p style="margin:6px 0 0;opacity:.9">Échéances à surveiller</p>
    </div>

    <div class="content">
        <p>Bonjour <strong>{{ $admin->name }}</strong>,</p>
        <p>Voici les échéances qui entrent dans leur période de rappel ou qui sont dépassées :</p>

        @foreach ($items as $item)
            <div class="item {{ $item['overdue'] ? 'overdue' : '' }}">
                @if ($item['link'])
                    <a href="{{ $baseUrl . $item['link'] }}">{{ $item['title'] }}</a>
                @else
                    <strong>{{ $item['title'] }}</strong>
                @endif
                @if ($item['body'])
                    <div style="font-size:13px;color:#475569">{{ $item['body'] }}</div>
                @endif
            </div>
        @endforeach

        <p style="text-align:center;margin:24px 0 8px">
            <a href="{{ $baseUrl }}/admin/echeances" class="button">Voir toutes les échéances</a>
        </p>
    </div>

    <div class="footer">
        {{ $cabinetName }}<br>
        Vous recevez ce récapitulatif car vous suivez les dossiers du cabinet.
    </div>
</body>
</html>
