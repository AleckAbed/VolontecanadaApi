<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LlmService
{
    public function askGemini(string $systemPrompt, array $history, string $userMessage): string
    {
        $apiKey = config('services.gemini.api_key');
        $model = config('services.gemini.model', 'gemini-2.5-flash-lite');

        if (!$apiKey) {
            throw new \RuntimeException('GEMINI_API_KEY non configurée');
        }

        $contents = [];
        foreach ($history as $msg) {
            $role = ($msg['role'] ?? 'user') === 'assistant' ? 'model' : 'user';
            $text = (string) ($msg['content'] ?? '');
            if ($text === '') continue;
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => $text]],
            ];
        }
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $userMessage]],
        ];

        $payload = [
            'systemInstruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => 0.4,
                'maxOutputTokens' => 600,
            ],
        ];

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $response = Http::timeout(20)->post($url, $payload);

        if (!$response->successful()) {
            Log::error('Gemini API error', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException('Le service de chat est temporairement indisponible.');
        }

        $data = $response->json();
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (!$text) {
            Log::warning('Gemini empty response', ['body' => $data]);
            return "Je n'ai pas pu générer de réponse. Pouvez-vous reformuler votre question ?";
        }

        return trim($text);
    }
}
