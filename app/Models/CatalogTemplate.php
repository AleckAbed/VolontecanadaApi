<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modèle du catalogue de la plateforme (pas rattaché à un cabinet) : PDF et
 * correspondance des champs prêts à l'emploi, importés par les cabinets.
 */
class CatalogTemplate extends Model
{
    protected $fillable = ['name', 'description', 'service_name', 'target_location', 'doc_type', 'category', 'pdf_path', 'field_map', 'version', 'is_published', 'created_by'];

    protected $casts = [
        'field_map' => 'array',
        'is_published' => 'boolean',
        'version' => 'integer',
        'imports_count' => 'integer',
    ];

    public function fieldCount(): int
    {
        return count($this->field_map ?? []);
    }
}
