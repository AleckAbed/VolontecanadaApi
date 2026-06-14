<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FileFolder extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'parent_id',
        'color',
        'visibility',
        'lock_code_hash',
        'created_by',
    ];

    protected $hidden = ['lock_code_hash'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(FileFolder::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(FileFolder::class, 'parent_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FileItem::class, 'folder_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    /** Admins explicitly granted access to a private folder. */
    public function permittedAdmins(): BelongsToMany
    {
        return $this->belongsToMany(Admin::class, 'file_folder_permissions', 'folder_id', 'admin_id')->withTimestamps();
    }

    public function isLocked(): bool
    {
        return !empty($this->lock_code_hash);
    }

    public function isPrivate(): bool
    {
        return $this->visibility === 'private';
    }

    /** Whether the given admin can see this folder. */
    public function isVisibleTo(?Admin $admin): bool
    {
        if (!$this->isPrivate()) return true;
        if (!$admin) return false;
        if ($this->created_by === $admin->id) return true;
        return $this->permittedAdmins()->where('admins.id', $admin->id)->exists();
    }

    /** Build breadcrumb path from root down to this folder. */
    public function breadcrumb(): array
    {
        $path = [];
        $cursor = $this;
        while ($cursor) {
            array_unshift($path, ['id' => $cursor->id, 'name' => $cursor->name]);
            $cursor = $cursor->parent;
        }
        return $path;
    }
}
