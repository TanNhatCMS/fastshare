<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class File extends Model
{
    use HasFactory;
    use SoftDeletes;

    const TYPE = ['link', 'document', 'image', 'video', 'audio', 'file'];
    const IMAGE_MIME = ['image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/svg+xml', 'image/webp'];
    const VIDEO_MIME = ['video/mp4', 'video/ogg', 'video/webm'];
    const AUDIO_MIME = ['audio/mpeg', 'audio/ogg', 'audio/wav'];
    const DOCUMENT_MIME = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/x-7z-compressed', 'application/x-tar', 'application/x-gzip'];

    protected $fillable = [
        'name',
        'path',
        'type',
        'size',
        'folder_id',
    ];

    protected $appends = ['_display', 'permissions'];

    public function getDisplayAttribute(): true
    {
        return true;
    }

    public function getPermissionsAttribute(): array
    {
        $user = auth()->user();
        $permissions = Folder::PERMISSIONS;
        $result = [];
        foreach ($permissions as $permission) {
            $result[$permission] = $this->hasPermission($user, $permission);
        }

        return $result;
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasPermission($user, string $permission): bool
    {
        $folder = $this->folder;

        if ($folder) {
            return $user->can("Folder.{$folder->id}.{$permission}");
        }

        return false;
    }

    public function filePermissions($user): array
    {
        $permissions = Folder::PERMISSIONS;
        $result = [];
        foreach ($permissions as $permission) {
            $result[$permission] = $this->hasPermission($user, $permission);
        }

        return $result;
    }
}
