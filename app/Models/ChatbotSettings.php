<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatbotSettings extends Model
{
    protected $table = 'chatbot_settings';

    protected $fillable = [
        'allow_immigration_questions',
        'immigration_questions_for',
        'allow_dossier_lookup',
        'allow_client_lookup',
        'custom_instructions',
    ];

    protected $casts = [
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
                'allow_immigration_questions' => false,
                'immigration_questions_for' => 'admin',
                'allow_dossier_lookup' => false,
                'allow_client_lookup' => false,
            ]);
        }
        return $row;
    }
}
