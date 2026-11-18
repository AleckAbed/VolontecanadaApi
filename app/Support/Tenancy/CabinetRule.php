<?php

namespace App\Support\Tenancy;

use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Rule;

/**
 * Règles de validation limitées au cabinet courant.
 *
 * Les règles texte `exists:table,id` et `unique:table,col` interrogent la base
 * directement, sans passer par CabinetScope : elles accepteraient l'id d'un
 * client ou d'un dossier d'un autre cabinet. Utiliser ces helpers à la place.
 */
final class CabinetRule
{
    public static function exists(string $table, string $column = 'id'): Exists
    {
        return Rule::exists($table, $column)->where('cabinet_id', CurrentCabinet::require());
    }

    public static function unique(string $table, string $column, mixed $ignoreId = null): Unique
    {
        $rule = Rule::unique($table, $column)->where('cabinet_id', CurrentCabinet::require());

        return $ignoreId === null ? $rule : $rule->ignore($ignoreId);
    }
}
