<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>{{ $isReceipt ? 'Reçu' : 'Facture' }} {{ $invoice->number }} — {{ $cabinetName }}</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 620px; margin: 0 auto; padding: 20px; background-color: #fafafa; }
        .header { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: white; padding: 24px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background-color: #ffffff; padding: 26px; border: 1px solid #e5e7eb; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; margin: 16px 0; }
        td { padding: 6px 0; border-bottom: 1px solid #f1f5f9; }
        td.amount { text-align: right; white-space: nowrap; }
        tr.total td { font-weight: bold; font-size: 16px; border-bottom: none; padding-top: 10px; }
        .button { display: inline-block; background: #2563eb; color: #ffffff !important; padding: 13px 30px; text-decoration: none; border-radius: 8px; font-weight: bold; }
        .paid { background: #ecfdf5; border-left: 4px solid #10b981; padding: 12px 16px; border-radius: 4px; margin: 16px 0; }
        .footer { background-color: #eff6ff; padding: 16px; text-align: center; font-size: 12px; color: #475569; border-radius: 0 0 8px 8px; border-top: 2px solid #dbeafe; }
    </style>
</head>
<body>
    <div class="header">
        <h1 style="margin:0;font-size:22px">{{ $cabinetName }}</h1>
        <p style="margin:6px 0 0;opacity:.9">{{ $isReceipt ? 'Reçu de paiement' : 'Facture' }} n° {{ $invoice->number }}</p>
    </div>

    <div class="content">
        <p>Bonjour <strong>{{ $invoice->client?->first_name }} {{ $invoice->client?->last_name }}</strong>,</p>

        @if ($isReceipt)
            <div class="paid">
                Merci ! Nous avons bien reçu votre paiement de <strong>{{ $money($invoice->total_cents) }}</strong>
                le {{ $invoice->paid_at?->format('Y-m-d') }}.
            </div>
        @else
            <p>Voici votre facture de <strong>{{ $cabinetName }}</strong>.
                @if ($invoice->due_date) Elle est payable au plus tard le <strong>{{ $invoice->due_date->format('Y-m-d') }}</strong>. @endif
            </p>
        @endif

        <table>
            @foreach ($invoice->lines as $line)
                <tr>
                    <td>{{ $line->description }}@if ($line->quantity != 1) × {{ rtrim(rtrim(number_format($line->quantity, 2, ',', ''), '0'), ',') }}@endif</td>
                    <td class="amount">{{ $money($line->amount_cents) }}</td>
                </tr>
            @endforeach
            <tr><td>Sous-total</td><td class="amount">{{ $money($invoice->subtotal_cents) }}</td></tr>
            @foreach ($invoice->tax_lines ?? [] as $tax)
                <tr><td>{{ $tax['name'] }} ({{ rtrim(rtrim(number_format($tax['rate'], 3, ',', ''), '0'), ',') }} %)</td><td class="amount">{{ $money($tax['amount_cents']) }}</td></tr>
            @endforeach
            <tr class="total"><td>Total</td><td class="amount">{{ $money($invoice->total_cents) }}</td></tr>
        </table>

        <p style="text-align:center;margin:24px 0 8px">
            <a href="{{ $url }}" class="button">{{ $isReceipt ? 'Voir mon reçu' : 'Voir et payer ma facture' }}</a>
        </p>
    </div>

    <div class="footer">
        {{ $cabinetName }}<br>
        Pour toute question sur cette facture, répondez à votre conseiller.
    </div>
</body>
</html>
