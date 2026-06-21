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

        try {
            $answer = $this->llm->askGemini(
                $systemPrompt,
                $data['history'] ?? [],
                $data['message']
            );
        } catch (\Throwable $e) {
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
            'allow_immigration_questions' => 'boolean',
            'immigration_questions_for' => 'in:admin,collab,both',
            'allow_dossier_lookup' => 'boolean',
            'allow_client_lookup' => 'boolean',
            'custom_instructions' => 'nullable|string|max:2000',
        ]);

        $settings = ChatbotSettings::current();
        $settings->update($data);

        return response()->json([
            'success' => true,
            'data' => $settings->fresh(),
        ]);
    }
}
