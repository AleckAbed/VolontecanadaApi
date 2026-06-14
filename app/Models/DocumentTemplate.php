<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'service_name',
        'category',
        'category_id',
        'pdf_path',
        'template_json',
        'created_by',
        'is_active',
    ];

    protected $casts = [
        'template_json' => 'array',
        'is_active' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function categoryRel(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(DocumentRequest::class, 'template_id');
    }

    public static function categoryOptions(): array
    {
        return [
            'ircc' => 'Formulaire IRCC',
            'cabinet' => 'Document Cabinet',
            'contrat' => 'Contrat',
            'autre' => 'Autre',
        ];
    }

    public function hasSchema(): bool
    {
        return !empty($this->template_json);
    }
}
