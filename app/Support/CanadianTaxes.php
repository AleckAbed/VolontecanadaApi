<?php

namespace App\Support;

/**
 * Taxes de vente canadiennes sur les services (honoraires).
 *
 * Règle appliquée : lieu de fourniture = province du client si elle est
 * connue, sinon celle du cabinet. Taux par défaut (TPS 5 %, TVH selon la
 * province, TVQ 9,975 % au Québec). Les taxes provinciales de vente
 * (TVP de la C.-B., de la Saskatchewan, du Manitoba) ne sont PAS appliquées
 * par défaut : leur application aux services d'immigration dépend du cas.
 * Chaque ligne de facture peut être marquée non taxable.
 *
 * ⚠️ À faire valider par un comptable avant la mise en production.
 */
final class CanadianTaxes
{
    /** province => [[nom, taux en %], ...] */
    public const RATES = [
        'AB' => [['TPS', 5.0]],
        'BC' => [['TPS', 5.0]],
        'MB' => [['TPS', 5.0]],
        'NB' => [['TVH', 15.0]],
        'NL' => [['TVH', 15.0]],
        'NS' => [['TVH', 14.0]],
        'NT' => [['TPS', 5.0]],
        'NU' => [['TPS', 5.0]],
        'ON' => [['TVH', 13.0]],
        'PE' => [['TVH', 15.0]],
        'QC' => [['TPS', 5.0], ['TVQ', 9.975]],
        'SK' => [['TPS', 5.0]],
        'YT' => [['TPS', 5.0]],
    ];

    /** Province du client à partir de son profil ou de son adresse, si reconnaissable. */
    public static function normalizeProvince(?string $value): ?string
    {
        if (! $value) {
            return null;
        }
        $v = strtoupper(trim($value));
        if (isset(self::RATES[$v])) {
            return $v;
        }
        $names = [
            'QUEBEC' => 'QC', 'QUÉBEC' => 'QC', 'ONTARIO' => 'ON', 'ALBERTA' => 'AB', 'MANITOBA' => 'MB',
            'SASKATCHEWAN' => 'SK', 'YUKON' => 'YT', 'NUNAVUT' => 'NU',
            'COLOMBIE-BRITANNIQUE' => 'BC', 'BRITISH COLUMBIA' => 'BC',
            'NOUVELLE-ÉCOSSE' => 'NS', 'NOUVELLE-ECOSSE' => 'NS', 'NOVA SCOTIA' => 'NS',
            'NOUVEAU-BRUNSWICK' => 'NB', 'NEW BRUNSWICK' => 'NB',
            'TERRE-NEUVE-ET-LABRADOR' => 'NL', 'NEWFOUNDLAND AND LABRADOR' => 'NL',
            'ÎLE-DU-PRINCE-ÉDOUARD' => 'PE', 'ILE-DU-PRINCE-EDOUARD' => 'PE', 'PRINCE EDWARD ISLAND' => 'PE',
            'TERRITOIRES DU NORD-OUEST' => 'NT', 'NORTHWEST TERRITORIES' => 'NT',
        ];

        return $names[$v] ?? null;
    }

    /**
     * Calcule les taxes d'un montant taxable (en cents).
     *
     * @return array<int, array{name: string, rate: float, amount_cents: int}>
     */
    public static function compute(int $taxableCents, ?string $province): array
    {
        $rates = self::RATES[$province ?? ''] ?? self::RATES['QC'];
        $lines = [];
        foreach ($rates as [$name, $rate]) {
            $lines[] = [
                'name' => $name,
                'rate' => $rate,
                'amount_cents' => (int) round($taxableCents * $rate / 100),
            ];
        }

        return $lines;
    }
}
