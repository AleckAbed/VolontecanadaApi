<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\FileFolder;
use App\Models\FileItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class FileManagerController extends Controller
{
    /**
     * List the contents of a folder (or root if folder_id is omitted).
     * Returns: breadcrumb + child folders + items.
     * If the folder is locked, the request must provide ?unlock=XXXX matching the hash.
     */
    public function index(Request $request)
    {
        $folderId = $request->integer('folder_id') ?: null;
        $folder = null;
        $admin = $request->user('admin');

        if ($folderId) {
            $folder = FileFolder::find($folderId);
            if (!$folder) {
                return response()->json(['success' => false, 'message' => 'Dossier introuvable'], 404);
            }
            if (!$folder->isVisibleTo($admin)) {
                return response()->json(['success' => false, 'message' => 'Accès refusé'], 403);
            }
            if ($folder->isLocked()) {
                $unlock = $request->input('unlock');
                if (!$unlock || !Hash::check((string) $unlock, $folder->lock_code_hash)) {
                    return response()->json([
                        'success' => false,
                        'locked' => true,
                        'message' => 'Ce dossier est verrouillé. Saisissez le code à 4 chiffres.',
                    ], 423);
                }
            }
        }

        $childrenQuery = FileFolder::where('parent_id', $folderId)->orderBy('name');
        if ($admin) {
            $childrenQuery->where(function ($q) use ($admin) {
                $q->where('visibility', 'public')
                  ->orWhere('created_by', $admin->id)
                  ->orWhereIn('id', function ($sub) use ($admin) {
                      $sub->select('folder_id')->from('file_folder_permissions')->where('admin_id', $admin->id);
                  });
            });
        }
        $children = $childrenQuery->get()->map(fn ($f) => [
            'id' => $f->id,
            'name' => $f->name,
            'color' => $f->color,
            'visibility' => $f->visibility,
            'is_private' => $f->isPrivate(),
            'is_locked' => $f->isLocked(),
            'is_owner' => $admin ? $f->created_by === $admin->id : false,
            'items_count' => $f->items()->count(),
            'children_count' => $f->children()->count(),
            'created_at' => $f->created_at?->format('Y-m-d H:i'),
        ]);

        $items = FileItem::where('folder_id', $folderId)
            ->orderByDesc('is_favorite')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($i) => [
                'id' => $i->id,
                'name' => $i->name,
                'original_filename' => $i->original_filename,
                'mime_type' => $i->mime_type,
                'size' => $i->size,
                'is_favorite' => $i->is_favorite,
                'created_at' => $i->created_at?->format('Y-m-d H:i'),
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'folder' => $folder ? [
                    'id' => $folder->id,
                    'name' => $folder->name,
                    'parent_id' => $folder->parent_id,
                    'is_locked' => $folder->isLocked(),
                ] : null,
                'breadcrumb' => $folder ? $folder->breadcrumb() : [],
                'folders' => $children,
                'items' => $items,
            ],
        ]);
    }

    /** Create a new folder (root or nested). */
    public function storeFolder(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|exists:file_folders,id',
            'color' => 'nullable|string|max:32',
            'lock_code' => 'nullable|string|regex:/^\d{4}$/',
            'visibility' => 'nullable|in:public,private',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $admin = $request->user('admin');

        $folder = FileFolder::create([
            'name' => $request->name,
            'parent_id' => $request->parent_id,
            'color' => $request->color,
            'visibility' => $request->input('visibility', 'public'),
            'lock_code_hash' => $request->filled('lock_code') ? Hash::make((string) $request->lock_code) : null,
            'created_by' => $admin?->id,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $folder->id,
                'name' => $folder->name,
                'parent_id' => $folder->parent_id,
                'visibility' => $folder->visibility,
                'is_locked' => $folder->isLocked(),
            ],
        ], 201);
    }

    /** Get permissions (visibility + permitted admin ids) for a folder. Only owner may call. */
    public function getFolderPermissions(Request $request, $id)
    {
        $folder = FileFolder::findOrFail($id);
        $admin = $request->user('admin');
        if ($admin && $folder->created_by !== null && $folder->created_by !== $admin->id) {
            // non-owners can only get permissions if the folder is currently visible to them
            if (!$folder->isVisibleTo($admin)) {
                return response()->json(['success' => false, 'message' => 'Accès refusé'], 403);
            }
        }

        $admins = Admin::select('id', 'name', 'email')->orderBy('name')->get();
        $permitted = $folder->permittedAdmins()->pluck('admins.id')->toArray();

        return response()->json([
            'success' => true,
            'data' => [
                'visibility' => $folder->visibility ?? 'public',
                'owner_id' => $folder->created_by,
                'permitted_admin_ids' => $permitted,
                'admins' => $admins,
            ],
        ]);
    }

    /** Update visibility + list of permitted admins. Only the owner may call. */
    public function updateFolderPermissions(Request $request, $id)
    {
        $folder = FileFolder::findOrFail($id);
        $admin = $request->user('admin');
        if ($admin && $folder->created_by !== null && $folder->created_by !== $admin->id) {
            return response()->json(['success' => false, 'message' => 'Seul le créateur peut modifier le partage'], 403);
        }

        $validator = Validator::make($request->all(), [
            'visibility' => 'required|in:public,private',
            'admin_ids' => 'array',
            'admin_ids.*' => 'integer|exists:admins,id',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::transaction(function () use ($folder, $request) {
            $folder->visibility = $request->input('visibility');
            $folder->save();

            $ids = (array) $request->input('admin_ids', []);
            // Owner is always implicitly granted — strip if present
            if ($folder->created_by) {
                $ids = array_values(array_filter($ids, fn ($v) => (int) $v !== (int) $folder->created_by));
            }
            $folder->permittedAdmins()->sync($ids);
        });

        return response()->json(['success' => true]);
    }

    /** List admins (for share picker). */
    public function listAdmins(Request $request)
    {
        $admins = Admin::select('id', 'name', 'email')->orderBy('name')->get();
        return response()->json(['success' => true, 'data' => $admins]);
    }

    /** Rename / change color / change lock of a folder. */
    public function updateFolder(Request $request, $id)
    {
        $folder = FileFolder::findOrFail($id);
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'color' => 'nullable|string|max:32',
            'lock_code' => 'nullable|string|regex:/^\d{4}$/',
            'remove_lock' => 'sometimes|boolean',
            'current_unlock' => 'nullable|string|regex:/^\d{4}$/',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        // To change/remove a lock, require the current code (if any)
        if ($folder->isLocked() && ($request->boolean('remove_lock') || $request->filled('lock_code'))) {
            $current = $request->input('current_unlock');
            if (!$current || !Hash::check((string) $current, $folder->lock_code_hash)) {
                return response()->json(['success' => false, 'message' => 'Code actuel incorrect'], 423);
            }
        }

        if ($request->has('name')) $folder->name = $request->name;
        if ($request->has('color')) $folder->color = $request->color;
        if ($request->boolean('remove_lock')) {
            $folder->lock_code_hash = null;
        } elseif ($request->filled('lock_code')) {
            $folder->lock_code_hash = Hash::make((string) $request->lock_code);
        }
        $folder->save();

        return response()->json(['success' => true, 'data' => [
            'id' => $folder->id,
            'name' => $folder->name,
            'is_locked' => $folder->isLocked(),
        ]]);
    }

    /** Delete a folder (and all nested contents). */
    public function destroyFolder(Request $request, $id)
    {
        $folder = FileFolder::findOrFail($id);
        if ($folder->isLocked()) {
            $unlock = $request->input('unlock');
            if (!$unlock || !Hash::check((string) $unlock, $folder->lock_code_hash)) {
                return response()->json(['success' => false, 'message' => 'Code requis pour supprimer'], 423);
            }
        }
        $this->deleteFolderRecursive($folder);
        return response()->json(['success' => true]);
    }

    /** Upload one or several files into a folder. */
    public function uploadItem(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'folder_id' => 'nullable|exists:file_folders,id',
            'files'     => 'required',
            'files.*'   => 'file|max:51200', // 50 MB max per file
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        // If folder is locked, require unlock
        $folder = $request->folder_id ? FileFolder::find($request->folder_id) : null;
        if ($folder && $folder->isLocked()) {
            $unlock = $request->input('unlock');
            if (!$unlock || !Hash::check((string) $unlock, $folder->lock_code_hash)) {
                return response()->json(['success' => false, 'message' => 'Code requis pour téléverser'], 423);
            }
        }

        $admin = $request->user('admin');
        $uploaded = [];

        $files = $request->file('files');
        if (!is_array($files)) $files = [$files];

        foreach ($files as $file) {
            $original = $file->getClientOriginalName();
            $path = $file->store('file-manager');
            $item = FileItem::create([
                'folder_id' => $request->folder_id,
                'name' => $original,
                'original_filename' => $original,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'path' => $path,
                'created_by' => $admin?->id,
            ]);
            $uploaded[] = [
                'id' => $item->id,
                'name' => $item->name,
                'size' => $item->size,
                'mime_type' => $item->mime_type,
            ];
        }

        return response()->json(['success' => true, 'data' => $uploaded], 201);
    }

    /** Download / view a file inline. */
    public function downloadItem(Request $request, $id)
    {
        $item = FileItem::findOrFail($id);
        // If parent folder is locked, require unlock
        if ($item->folder_id) {
            $folder = FileFolder::find($item->folder_id);
            if ($folder && $folder->isLocked()) {
                $unlock = $request->input('unlock');
                if (!$unlock || !Hash::check((string) $unlock, $folder->lock_code_hash)) {
                    return response()->json(['success' => false, 'message' => 'Code requis'], 423);
                }
            }
        }
        if (!Storage::disk('local')->exists($item->path)) {
            abort(404);
        }
        return response()->file(Storage::disk('local')->path($item->path), [
            'Content-Disposition' => 'inline; filename="' . addslashes($item->original_filename) . '"',
        ]);
    }

    /** Toggle favorite status of a file. */
    public function toggleFavoriteItem(Request $request, $id)
    {
        $item = FileItem::findOrFail($id);
        if ($item->folder_id) {
            $folder = FileFolder::find($item->folder_id);
            if ($folder && $folder->isLocked()) {
                $unlock = $request->input('unlock');
                if (!$unlock || !Hash::check((string) $unlock, $folder->lock_code_hash)) {
                    return response()->json(['success' => false, 'message' => 'Code requis'], 423);
                }
            }
        }
        $item->is_favorite = !$item->is_favorite;
        $item->save();
        return response()->json(['success' => true, 'data' => ['is_favorite' => $item->is_favorite]]);
    }

    public function destroyItem(Request $request, $id)
    {
        $item = FileItem::findOrFail($id);
        if ($item->folder_id) {
            $folder = FileFolder::find($item->folder_id);
            if ($folder && $folder->isLocked()) {
                $unlock = $request->input('unlock');
                if (!$unlock || !Hash::check((string) $unlock, $folder->lock_code_hash)) {
                    return response()->json(['success' => false, 'message' => 'Code requis'], 423);
                }
            }
        }
        if (Storage::disk('local')->exists($item->path)) {
            Storage::disk('local')->delete($item->path);
        }
        $item->delete();
        return response()->json(['success' => true]);
    }

    /** Verify a folder lock code (used by the front to unlock UI). */
    public function verifyLock(Request $request, $id)
    {
        $folder = FileFolder::findOrFail($id);
        $request->validate(['code' => 'required|string|regex:/^\d{4}$/']);
        if (!$folder->isLocked()) {
            return response()->json(['success' => true]);
        }
        if (!Hash::check((string) $request->code, $folder->lock_code_hash)) {
            return response()->json(['success' => false, 'message' => 'Code incorrect'], 423);
        }
        return response()->json(['success' => true]);
    }

    private function deleteFolderRecursive(FileFolder $folder): void
    {
        foreach ($folder->children as $child) {
            $this->deleteFolderRecursive($child);
        }
        foreach ($folder->items as $item) {
            if (Storage::disk('local')->exists($item->path)) {
                Storage::disk('local')->delete($item->path);
            }
            $item->delete();
        }
        $folder->delete();
    }
}
