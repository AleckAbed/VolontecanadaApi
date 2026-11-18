<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatbotSettings;
use App\Services\ChatbotContextResolver;
use App\Services\ChatbotKnowledge;
use App\Services\LlmService;
use Illuminate\Http\Request;

class ChatbotController extends Controller
{
    public function __construct(
        private LlmService $llm,
        private ChatbotContextResolver $resolver,
    ) {
    }

    public function askAdmin(Request $request)
    {
        return $this->ask($request, 'admin');
    }

    public function askCollab(Request $request)
    {
        return $this->ask($request, 'collab');
    }

    private function ask(Request $request, string $audience)
    {
        $data = $request->validate([
            'message' => 'required|string|min:1|max:2000',
            'history' => 'array|max:20',
            'history.*.role' => 'in:user,assistant',
            'history.*.content' => 'string|max:4000',
        ]);

        $settings = ChatbotSettings::current();
        // 2.3 : les questions partent chez un fournisseur d'IA hors Canada ;
        // rien n'est envoyé tant qu'un gestionnaire du cabinet ne l'a pas activé.
        if (! $settings->enabled) {
            return response()->json([
                'success' => false,
                'code' => 'assistant_disabled',
                'message' => "L'assistant IA est désactivé pour ce cabinet. Un propriétaire ou un administrateur peut l'activer dans les paramètres de l'assistant.",
            ], 403);
        }

        try {
            $resolved = $this->resolver->resolve($data['message'], $settings);

            // Si le resolver retourne une désambiguïsation, on court-circuite l'appel IA.
            if (is_array($resolved) && isset($resolved['disambiguation'])) {
                return response()->json([
                    'success' => true,
                    'data' => $resolved,
                ]);
            }

            $contextData = is_string($resolved) ? $resolved : null;
            $systemPrompt = ChatbotKnowledge::buildSystemPrompt($audience, $settings, $contextData);

            $answer = $this->llm->askGemini(
                $systemPrompt,
                $data['history'] ?? [],
                $data['message']
            );
        } catch (\Throwable $e) {
            \Log::error('Chatbot ask failed', [
                'audience' => $audience,
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 503);
        }

        return response()->json([
            'success' => true,
            'data' => ['answer' => $answer],
        ]);
    }

    // ====== Paramètres (admin seulement) ======

    public function getSettings()
    {
        return response()->json([
            'success' => true,
            'data' => ChatbotSettings::current(),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'enabled' => 'boolean',
            'accept_foreign_processing' => 'required_if:enabled,true|accepted_if:enabled,true',
            'allow_immigration_questions' => 'boolean',
            'immigration_questions_for' => 'in:admin,collab,both',
            'allow_dossier_lookup' => 'boolean',
            'allow_client_lookup' => 'boolean',
            'custom_instructions' => 'nullable|string|max:2000',
        ]);

        $settings = ChatbotSettings::current();
        unset($data['accept_foreign_processing']);
        if (array_key_exists('enabled', $data) && $data['enabled'] !== $settings->enabled) {
            $data['enabled_at'] = $data['enabled'] ? now() : null;
            $data['enabled_by'] = $data['enabled'] ? $request->user()->id : null;
        }
        $settings->update($data);

        return response()->json([
            'success' => true,
            'data' => $settings->fresh(),
        ]);
    }
}
