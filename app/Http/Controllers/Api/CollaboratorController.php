<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\CollaboratorWelcomeMail;
use App\Models\Collaborator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CollaboratorController extends Controller
{
    public function index()
    {
        $collabs = Collaborator::orderBy('last_name')->orderBy('first_name')->get();
        return response()->json([
            'success' => true,
            'data' => $collabs->map(fn($c) => $this->format($c)),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => 'required|email|unique:collaborators,email',
            'password' => 'required|string|min:6',
            'phone' => 'nullable|string|max:50',
            'is_active' => 'nullable|boolean',
        ]);
        $data['password'] = Hash::make($data['password']);
        $data['is_active'] = $request->boolean('is_active', true);
        $collab = Collaborator::create($data);
        return response()->json(['success' => true, 'data' => $this->format($collab)], 201);
    }

    public function show($id)
    {
        $collab = Collaborator::findOrFail($id);
        return response()->json(['success' => true, 'data' => $this->format($collab)]);
    }

    public function update(Request $request, $id)
    {
        $collab = Collaborator::findOrFail($id);
        $data = $request->validate([
            'first_name' => 'sometimes|required|string|max:100',
            'last_name' => 'sometimes|required|string|max:100',
            'email' => 'sometimes|required|email|unique:collaborators,email,' . $collab->id,
            'phone' => 'nullable|string|max:50',
            'is_active' => 'nullable|boolean',
            'password' => 'nullable|string|min:6',
        ]);
        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }
        $collab->update($data);
        return response()->json(['success' => true, 'data' => $this->format($collab->fresh())]);
    }

    public function destroy($id)
    {
        $collab = Collaborator::findOrFail($id);
        // Révoque tous ses tokens d'abord
        $collab->tokens()->delete();
        $collab->delete();
        return response()->json(['success' => true]);
    }

    /**
     * Envoie (ou ré-envoie) au collaborateur un email d'activation contenant un
     * lien unique vers la page de définition du mot de passe.
     * Génère un nouveau token à chaque appel (révoque l'ancien).
     */
    public function sendWelcomeLink($id)
    {
        $collab = Collaborator::findOrFail($id);

        $token = Str::random(64);
        $collab->activation_token = $token;
        $collab->activation_token_expires_at = now()->addDays(7);
        $collab->save();

        try {
            Mail::to($collab->email)->send(new CollaboratorWelcomeMail($collab, $token));
            return response()->json([
                'success' => true,
                'message' => 'Lien d\'activation envoyé à ' . $collab->email,
            ]);
        } catch (\Throwable $e) {
            Log::error('Erreur envoi lien collaborateur', ['id' => $collab->id, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Échec de l\'envoi : ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Vérifie un token d'activation et renvoie les infos publiques du collab
     * (juste de quoi afficher « Bonjour {prénom} » sur la page).
     */
    public function checkActivationToken(Request $request)
    {
        $request->validate(['token' => 'required|string']);
        $collab = Collaborator::where('activation_token', $request->input('token'))->first();
        if (!$collab) {
            return response()->json(['success' => false, 'message' => 'Lien invalide ou déjà utilisé.'], 404);
        }
        if ($collab->activation_token_expires_at && $collab->activation_token_expires_at->isPast()) {
            return response()->json(['success' => false, 'message' => 'Ce lien a expiré. Demandez-en un nouveau à l\'administrateur.'], 410);
        }
        return response()->json([
            'success' => true,
            'data' => [
                'first_name' => $collab->first_name,
                'last_name' => $collab->last_name,
                'email' => $collab->email,
            ],
        ]);
    }

    /**
     * Active le compte : valide le token + applique le nouveau mot de passe.
     * Règles de sécurité : 8 caractères mini, majuscule, minuscule, chiffre, spécial.
     */
    public function activate(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'password' => [
                'required', 'string', 'confirmed', 'min:8',
                'regex:/[A-Z]/',     // majuscule
                'regex:/[a-z]/',     // minuscule
                'regex:/[0-9]/',     // chiffre
                'regex:/[^A-Za-z0-9]/', // caractère spécial
            ],
        ], [
            'password.regex' => 'Le mot de passe doit contenir au moins une majuscule, une minuscule, un chiffre et un caractère spécial.',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
            'password.min' => 'Le mot de passe doit faire au moins 8 caractères.',
        ]);

        $collab = Collaborator::where('activation_token', $request->input('token'))->first();
        if (!$collab) {
            return response()->json(['success' => false, 'message' => 'Lien invalide ou déjà utilisé.'], 404);
        }
        if ($collab->activation_token_expires_at && $collab->activation_token_expires_at->isPast()) {
            return response()->json(['success' => false, 'message' => 'Ce lien a expiré.'], 410);
        }

        $collab->password = $request->input('password'); // hashé via cast 'hashed'
        $collab->is_active = true;
        $collab->activation_token = null;
        $collab->activation_token_expires_at = null;
        $collab->save();

        return response()->json([
            'success' => true,
            'message' => 'Compte activé. Vous pouvez maintenant vous connecter.',
        ]);
    }

    private function format(Collaborator $c): array
    {
        return [
            'id' => $c->id,
            'first_name' => $c->first_name,
            'last_name' => $c->last_name,
            'email' => $c->email,
            'phone' => $c->phone,
            'is_active' => (bool) $c->is_active,
            'dossiers_count' => $c->dossiers()->count(),
            'created_at' => $c->created_at?->format('Y-m-d H:i'),
        ];
    }
}
