<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\NotificationTypes;
use Illuminate\Http\Request;

/** Préférences de notification de la personne connectée. */
class NotificationPreferenceController extends Controller
{
    public function show(Request $request)
    {
        return response()->json(['success' => true, 'data' => NotificationTypes::forAdmin($request->user())]);
    }

    public function update(Request $request)
    {
        $data = $request->validate(['preferences' => 'required|array']);
        $admin = $request->user();
        $admin->forceFill(['notification_preferences' => NotificationTypes::normalize($data['preferences']) ?: null])->save();

        return response()->json(['success' => true, 'message' => 'Préférences enregistrées.', 'data' => NotificationTypes::forAdmin($admin->fresh())]);
    }
}
