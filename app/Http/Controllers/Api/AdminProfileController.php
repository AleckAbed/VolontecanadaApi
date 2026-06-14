<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AdminProfileController extends Controller
{
    /** Mettre à jour les infos générales (nom, email). */
    public function updateProfile(Request $request)
    {
        $admin = $request->user('admin');
        $validator = Validator::make($request->all(), [
            'name'  => 'sometimes|string|max:255',
            'email' => 'sometimes|email|max:255|unique:admins,email,' . $admin->id,
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }
        if ($request->filled('name')) $admin->name = $request->name;
        if ($request->filled('email')) $admin->email = $request->email;
        $admin->save();

        return response()->json(['success' => true, 'data' => [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => $admin->role,
        ]]);
    }

    /** Changer le mot de passe. */
    public function changePassword(Request $request)
    {
        $admin = $request->user('admin');
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:8|confirmed',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }
        if (!Hash::check($request->current_password, $admin->password)) {
            return response()->json(['success' => false, 'message' => 'Mot de passe actuel incorrect'], 422);
        }
        $admin->password = $request->new_password;
        $admin->save();
        return response()->json(['success' => true]);
    }

    /** Lire les réglages de l'écran de verrouillage. */
    public function getLockScreenSettings(Request $request)
    {
        $admin = $request->user('admin');
        return response()->json([
            'success' => true,
            'data' => [
                'interval'    => (int) ($admin->lock_screen_interval ?? 8),
                'backgrounds' => $this->mapBackgrounds($admin),
            ],
        ]);
    }

    /** Modifier l'intervalle. */
    public function updateLockScreenSettings(Request $request)
    {
        $admin = $request->user('admin');
        $validator = Validator::make($request->all(), [
            'interval' => 'required|integer|min:0|max:600',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }
        $admin->lock_screen_interval = (int) $request->interval;
        $admin->save();
        return response()->json(['success' => true]);
    }

    /** Téléverser une ou plusieurs images de fond. */
    public function uploadLockScreenBackground(Request $request)
    {
        $admin = $request->user('admin');
        $validator = Validator::make($request->all(), [
            'images'   => 'required',
            'images.*' => 'image|mimes:jpeg,jpg,png,webp|max:5120', // 5 MB
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }
        $files = $request->file('images');
        if (!is_array($files)) $files = [$files];

        $list = $admin->lock_screen_backgrounds ?? [];
        foreach ($files as $file) {
            $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
            $name = Str::uuid()->toString() . '.' . $ext;
            $file->storeAs('lock-screen/' . $admin->id, $name);
            $list[] = $name;
        }
        $admin->lock_screen_backgrounds = $list;
        $admin->save();

        return response()->json(['success' => true, 'data' => [
            'backgrounds' => $this->mapBackgrounds($admin),
        ]]);
    }

    /** Supprimer une image. */
    public function deleteLockScreenBackground(Request $request, string $filename)
    {
        $admin = $request->user('admin');
        $list = $admin->lock_screen_backgrounds ?? [];
        if (!in_array($filename, $list, true)) {
            return response()->json(['success' => false, 'message' => 'Image inconnue'], 404);
        }
        $path = 'lock-screen/' . $admin->id . '/' . $filename;
        if (Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }
        $admin->lock_screen_backgrounds = array_values(array_filter($list, fn ($n) => $n !== $filename));
        $admin->save();
        return response()->json(['success' => true]);
    }

    /** Servir un fichier image (auth via Sanctum). */
    public function serveLockScreenBackground(Request $request, string $filename)
    {
        $admin = $request->user('admin');
        $list = $admin->lock_screen_backgrounds ?? [];
        if (!in_array($filename, $list, true)) {
            abort(404);
        }
        $path = 'lock-screen/' . $admin->id . '/' . $filename;
        if (!Storage::disk('local')->exists($path)) {
            abort(404);
        }
        return response()->file(Storage::disk('local')->path($path));
    }

    private function mapBackgrounds($admin): array
    {
        $list = $admin->lock_screen_backgrounds ?? [];
        return array_map(fn ($name) => [
            'filename' => $name,
            'url'      => '/admin/profile/lock-screen-backgrounds/' . rawurlencode($name),
        ], $list);
    }
}
