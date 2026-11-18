<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminNotification;
use Illuminate\Http\Request;

/** Notifications de l'admin connecté (cloche de l'en-tête). */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $adminId = $request->user('admin')->id;

        $items = AdminNotification::where('admin_id', $adminId)->orderByDesc('created_at')->limit(30)->get()
            ->map(fn (AdminNotification $n) => [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->title,
                'body' => $n->body,
                'link' => $n->link,
                'read' => $n->read_at !== null,
                'created_at' => $n->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $items,
                'unread' => AdminNotification::where('admin_id', $adminId)->whereNull('read_at')->count(),
            ],
        ]);
    }

    public function markRead(Request $request, int $id)
    {
        AdminNotification::where('admin_id', $request->user('admin')->id)->whereKey($id)->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function markAllRead(Request $request)
    {
        AdminNotification::where('admin_id', $request->user('admin')->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }
}
