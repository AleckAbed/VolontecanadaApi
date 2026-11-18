<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCabinet;
use Illuminate\Database\Eloquent\Model;

class ChatbotSettings extends Model
{
    use Auditable;

    use BelongsToCabinet;

    protected $table = 'chatbot_settings';

    protected $fillable = [
        'enabled',
        'enabled_at',
        'enabled_by',
        'allow_immigration_questions',
        'immigration_questions_for',
        'allow_dossier_lookup',
        'allow_client_lookup',
        'custom_instructions',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'enabled_at' => 'datetime',
        'allow_immigration_questions' => 'boolean',
        'allow_dossier_lookup' => 'boolean',
        'allow_client_lookup' => 'boolean',
    ];

    /**
     * Retourne (ou crée) la ligne unique des paramètres.
     */
    public static function current(): self
    {
        $row = self::query()->first();
        if (!$row) {
            $row = self::create([
                'enabled' => false,
                'allow_immigration_questions' => false,
                'immigration_questions_for' => 'admin',
                'allow_dossier_lookup' => false,
                'allow_client_lookup' => false,
            ]);
        }
        return $row;
    }
}
