<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DossierDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'dossier_id',
        'document_template_id',
        'doc_type', // ircc | fo
        'name',
        'description',
        'template_path',
        'schema',
        'form_data',
        'filled_pdf_path',
        'filled_by', // admin | collab | client
        'status',
        'last_saved_at',
        'completed_at',
        'sort_order',
    ];

    protected $casts = [
        'schema' => 'array',
        'form_data' => 'array',
        'last_saved_at' => 'datetime',
        'completed_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    protected $appends = ['has_filled_pdf'];

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }

    public function getHasFilledPdfAttribute(): bool
    {
        return (bool) $this->filled_pdf_path;
    }

    public function markCompleted(): void
    {
        $this->status = 'completed';
        $this->completed_at = $this->completed_at ?: now();
        $this->save();
    }

    /**
     * Nettoie un form_data avant stockage.
     *
     * Les formulaires XFA dynamiques (LiveCycle Designer) produisent des entrées
     * contenant de l'UTF-8 invalide (ex : __as__NNNR.borderColor avec des octets
     * binaires). Le cast Eloquent 'array' fait un json_encode SANS flags, qui lève
     * une JsonEncodingException sur l'UTF-8 invalide → HTTP 500 au save.
     *
     * On fait un aller-retour json_encode/json_decode avec JSON_INVALID_UTF8_SUBSTITUTE
     * pour remplacer les octets invalides par U+FFFD et garantir un tableau encodable.
     */
    public static function sanitizeFormData($data)
    {
        if ($data === null) {
            return null;
        }
        if (!is_array($data)) {
            return $data;
        }
        $json = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false) {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
