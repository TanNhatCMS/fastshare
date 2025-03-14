<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class Folder extends Model
{
    use HasFactory;
    use SoftDeletes;

    const PERMISSIONS = ['create', 'read', 'update', 'delete'];
    const ROLES = ['admin', 'editor', 'viewer'];

    protected $fillable = [
        'name',
        'parent_id',
        'user_id',
    ];

    protected $appends = ['_display', 'permissions'];

    public function getPermissionsAttribute(): array
    {
        $user = auth()->user();
        $permissions = self::PERMISSIONS;

        $result = [];
        foreach ($permissions as $permission) {
            $result[$permission] = $user->can("Folder.{$this->id}.{$permission}");
        }

        return $result;
    }

    public function getDisplayAttribute(): true
    {
        return true;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'folder_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Folder::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }

    public function initAuthoredFolder($folder_id = null): void
    {
        if ($folder_id === null) {
            $folder_id = $this->id;
        }

        foreach (self::PERMISSIONS as $permission) {
            try {
                Permission::create(['name' => "Folder.{$folder_id}.{$permission}"]);
            } catch (\Exception $e) {
                Logger::error($e->getMessage());
            }
        }

        foreach (self::ROLES as $role) {
            $roleNew = Role::create(['name' => "Folder.{$folder_id}.{$role}"]);

            if ($role === 'admin') {
                $roleNew->givePermissionTo([
                    "Folder.{$folder_id}.create",
                    "Folder.{$folder_id}.read",
                    "Folder.{$folder_id}.update",
                    "Folder.{$folder_id}.delete",
                ]);
            }

            if ($role === 'editor') {
                $roleNew->givePermissionTo([
                    "Folder.{$folder_id}.create",
                    "Folder.{$folder_id}.read",
                    "Folder.{$folder_id}.update",
                ]);
            }

            if ($role === 'viewer') {
                $roleNew->givePermissionTo([
                    "Folder.{$folder_id}.read",
                ]);
            }
        }
    }

    public function hasPermission($user, string $permission): bool
    {
        $folderPermission = "Folder.{$this->id}.{$permission}";

        return $user?->can($folderPermission);
    }

    public function hasRole($user, string $role): bool
    {
        $folderRole = "Folder.{$this->id}.{$role}";

        return $user?->hasRole($folderRole);
    }

    public function members(): array
    {
        return [
            'admin'   => User::role("Folder.{$this->id}.admin")->get(),
            'editors' => User::role("Folder.{$this->id}.editor")->get(),
            'viewers' => User::role("Folder.{$this->id}.viewer")->get(),
        ];
    }

    public function updateChildrenPermission(string $permission): void
    {
        $this->children->each(function ($folder) use ($permission) {
            $folder->permission = $permission;
            $folder->save();
            $folder->updateChildrenPermission($permission);
        });
    }

    public function fullPath(): string
    {
        $path = [];
        $folder = $this;
        while ($folder->parent) {
            $path[] = $folder->name;
            $folder = $folder->parent;
        }

        return implode('/', array_reverse($path));
    }

//    public function folderPermission() : array
//    {
//        $permissions = explode(',', $this->permission);
//
//        return [
//            'create' => in_array('create', $permissions),
//            'read' => in_array('read', $permissions),
//            'update' => in_array('update', $permissions),
//            'delete' => in_array('delete', $permissions),
//        ];
//    }
    public function folderPermission(User $user): array
    {
        $permissions = self::PERMISSIONS;
        $result = [];
        foreach ($permissions as $permission) {
            $result[$permission] = $user->can("Folder.{$this->id}.{$permission}");
        }

        return $result;
    }

    public function deleteFilesInStorage(): void
    {
        $this->files->each(function ($file) {
            if ($file->type !== 'link' && $file->path != null) {
                Storage::delete($file->path);
            }
        });

        $this->children->each(function ($folder) {
            $folder->deleteFilesInStorage();
        });
    }

    public function deleteChildren(): void
    {
        $this->files->each(function ($file) {
            $file->delete();
        });

        $this->children->each(function ($folder) {
            $folder->delete();
            $folder->deleteChildren();
        });
    }

    public function restoreChildren(): void
    {
        $this->files()->onlyTrashed()->each(function ($file) {
            $file->restore();
        });

        $this->children()->onlyTrashed()->each(function ($folder) {
            $folder->restore();
            $folder->restoreChildren();
        });
    }

    public function displayBreadcrumb(): array
    {
        $folder = $this;
        $breadcrumb = [];

        while ($folder->parent) {
            $breadcrumb[] = $folder->name;
            $folder = $folder->parent;
        }

        return array_reverse($breadcrumb);
    }

    public function assignRolesToUser($user, $role): void
    {
        $user->assignRole("Folder.{$this->id}.{$role}");

        $this->children->each(function ($folder) use ($user, $role) {
            $folder->assignRolesToUser($user, $role);
        });
    }

    public function removeRolesFromUser($user, $role): void
    {
        $user->removeRole("Folder.{$this->id}.{$role}");

        $this->children->each(function ($folder) use ($user, $role) {
            $folder->removeRolesFromUser($user, $role);
        });
    }

    public function assignRoleAfterCreate(Folder $parent_folder): void
    {
        if ($parent_folder->folder_id !== null) {
            $members = $parent_folder->members();
            foreach ($members as $role => $users) {
                foreach ($users as $user) {
                    $role = str_replace('editors', 'editor', $role);
                    $role = str_replace('viewers', 'viewer', $role);
                    $this->assignRolesToUser($user, $role);
                }
            }
        }
    }

    /**
     * Lấy tất cả thư mục con bao gồm cả các cấp con của nó.
     *
     * @param int|null $parent_id
     *
     * @return Collection
     */
    public static function getAllChildren(?int $parent_id = 1): Collection
    {
        $folders = Folder::where('folder_id', $parent_id)->get();
        $folders->each(function ($folder) {
            $folder->childrens = Folder::getAllChildren($folder->id);
        });

        return $folders;
    }
}
