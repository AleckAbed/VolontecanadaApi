<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    /**
     * Login administrateur
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $admin = Admin::where('email', $request->email)->first();

        if (!$admin || !Hash::check($request->password, $admin->password)) {
            throw ValidationException::withMessages([
                'email' => ['Les informations d\'identification sont incorrectes.'],
            ]);
        }

        if (!$admin->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Votre compte est désactivé. Contactez un super administrateur.'],
            ]);
        }

        // Créer un token pour l'admin
        $token = $admin->createToken('admin-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Connexion réussie',
            'data' => [
                'admin' => [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                    'role' => $admin->role,
                ],
                'token' => $token,
            ],
        ]);
    }

    /**
     * Logout administrateur
     */
    public function logout(Request $request)
    {
        $request->user('admin')->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Déconnexion réussie',
        ]);
    }

    /**
     * Obtenir les informations de l'admin connecté
     */
    public function me(Request $request)
    {
        $admin = $request->user('admin');

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => $admin->role,
                'is_active' => $admin->is_active,
                'created_at' => $admin->created_at,
            ],
        ]);
    }
}


