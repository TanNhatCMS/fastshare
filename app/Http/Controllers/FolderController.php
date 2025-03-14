<?php

namespace App\Http\Controllers;

use App\Models\Folder;
use App\Models\FolderShortcut;
use App\Rules\CommaSeparatedArray;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FolderController extends BaseAPIController implements HasMiddleware
{

    protected array $request_data;
    protected Request $request;

    public function __construct(Request $request)
    {

        $this->requireID($request->id, [
            'folders.set-shortcut'
        ]);

        $this->request_data = $request->all();

        $this->request = $request;
    }

    public static function middleware(): array
    {
        $folder_id = request('id');

        return [
            new Middleware(middleware: "permission:Folder.{$folder_id}.create", only: ['store']),
            new Middleware(middleware: "permission:Folder.{$folder_id}.read", only: ['index']),
            new Middleware(middleware: "permission:Folder.{$folder_id}.update", only: ['update']),
            new Middleware(middleware: "permission:Folder.{$folder_id}.delete", only: ['destroy']),
            new Middleware(middleware: "permission:Folder.{$folder_id}.delete", only: ['deletePermanently']),
            new Middleware(middleware: "permission:Folder.{$folder_id}.delete", only: ['restore']),
            new Middleware(middleware: "permission:Folder.{$folder_id}.read", only: ['searchFile']),
        ];
    }

    public function rules(bool $id = false): array
    {
        $rules = [
            'name' => [
                'required',
                Rule::unique('folders')->where(function ($query) {
                    return $query->where('folder_id', $this->request->id);
                }),
            ],
            'folder_id' => 'nullable|exists:folders,id',
            'permission' => ['nullable', new CommaSeparatedArray(Folder::PERMISSIONS)]
        ];

        if ($id) $rules['id'] = 'required|exists:folders,id';

        return $rules;
    }

    public function messages(): array
    {
        return [
            'name.required' => __('messages.required', ['attribute' => 'tên']),
            'name.unique' => __('messages.unique', ['attribute' => 'Tên']),
            'folder_id.exists' => __('messages.exists', ['attribute' => 'Parent folder']),
            'id.required' => __('messages.required', ['attribute' => 'thư mục']),
            'id.exists' => __('messages.exists', ['attribute' => 'Thư mục']),
            'permission.in' => __('messages.in', ['attribute' => 'Permission']),
        ];
    }

    public function index(Request $request)
    {
        $this->checkPermissionFolder('read');

        $folder = Folder::with('parent', 'files', 'children')
            ->withCount('files')
            ->withCount('children')
            ->find($request->id);

        if (!$folder) return response()->json([
            'success' => false,
            'message' => __('messages.not_found', ['attribute' => 'Thư mục'])
        ], 404);
        $user = $request->user();
        if (!$user->hasRole('admin')) {
            $roles = $user->getRoleNames();
            $folder_ids = [];
            $list_roles = [];
            foreach ($roles as $role) {
                $role = explode('.', $role);
                if (count($role) < 3) {
                    continue;
                }
                $folder_id = $role[1];
                if (!in_array($folder_id, $folder_ids)) {
                    $list_roles[] = [
                        'id' => $folder_id,
                        'name' => $role[2]
                    ];
                    $folder_ids[] = $folder_id;
                }
            }
            $setRole = function ($folderItem) use ($list_roles) {
                if ($folderItem && in_array($folderItem->id, array_column($list_roles, 'id'))) {
                    $folderItem->role = $list_roles[array_search($folderItem->id, array_column($list_roles, 'id'))]['name'];
                }
                return $folderItem;
            };
            if (in_array($folder->id, array_column($list_roles, 'id'))) {
                $folder->role = $list_roles[array_search($folder->id, array_column($list_roles, 'id'))]['name'];
            }
            if ($folder->parent) {
                $folder_parent = $folder->parent;
                while ($folder_parent->parent) {
                    $folder_parent = $setRole($folder_parent);
                    $folder_parent = $folder_parent->parent;
                }
                $folder->parent = $folder_parent;
            }
            if ($folder->children) {
                $folder->children = $folder->children->map($setRole);
            }
        }
        $folder->children = $folder->children->map(function ($folder) use ($user){
            $folder->permission = $folder->folderPermission($user);
            return $folder;
        });

        //$folder->fullPath();

        $folder->permission = $folder->folderPermission($user);

        $folder->files = $folder->files->map(function ($file) {
            $file->upload_by = $file->user->name;
            unset($file->user);
            return $file;
        });

        return response()->json([
            'success' => true,
            'data' => [
                'folders' => $folder,
                'members' => $folder->members()
            ]
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $this->checkPermissionFolder('create');

        $this->validatorApi();

        $parent_folder = Folder::find($request->id);

        $permissions = gettype($request->permission) === 'array' ?
            implode(',', array_map('trim', $request->permission)) :
            $request->permission;

        if (!$user->hasRole('admin'))
            $permissions = $parent_folder->permission;

        $folder = new Folder;
        $folder->name = $request->name;
        $folder->folder_id = $request->id;
        $folder->permission = $permissions ?? 'create,read,update,delete';
        $folder->save();

        $folder->initAuthoredFolder();

        $roleAdminFolder = "Folder.{$folder->id}.admin";

        $user->assignRole($roleAdminFolder);

        $folder->permission = $folder->folderPermission($user);

        $folder->assignRoleAfterCreate($parent_folder);

        return response()->json([
            'message' => 'Folder created!',
            'data' => [
                "folder" => $folder,
                "m" => $parent_folder->members()
            ]
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $this->validatorApi(true);

        $this->checkPermissionFolder('update');

        $folder = Folder::find($request->id);

        $folder_exist = Folder::where('name', $request->name)
            ->where('folder_id', $folder->folder_id)
            ->where('id', '!=', $folder->id)
            ->exists();

        if ($folder_exist)
            return response()->json([
                'success' => false,
                'message' => __('messages.unique', ['attribute' => 'Tên thư mục'])
            ], 400);

        $folder->name = $request->name;

        if ($request->folder_id) $folder->folder_id = $request->folder_id;

        if ($request->filled('permission') && $user->hasRole('admin')) {

            $permissions = gettype($request->permission) === 'array' ?
                implode(',', array_map('trim', $request->permission)) :
                $request->permission;

            $folder->permission = $permissions;

            $folder->updateChildrenPermission($permissions);
            unset($folder->children);
        }

        $folder->save();

        $folder->permission = $folder->folderPermission($user);

        return response()->json([
            'message' => 'Đã chỉnh sửa thư mục!',
            'data' => [
                "folder" => $folder
            ]
        ]);
    }

    public function destroy(Request $request)
    {
        $this->checkPermissionFolder('delete', $request->id, true);

        $folder = Folder::withTrashed()
            ->with('files', 'children')
            ->find($request->id);

        if (!$folder)
            return response()->json([
                'success' => false,
                'message' => __('messages.not_found', ['attribute' => 'Folder'])
            ], 404);

        if ($folder->deleted_at !== null) {

            $message = __('messages.deleted_permanently', ['attribute' => 'Thư mục']);
            $folder->deleteFilesInStorage();
            $folder->forceDelete();
        } else {
            $message = __('messages.deleted', ['attribute' => 'Thư mục']);
            $folder->delete();
            $folder->deleteChildren();
        }

        return response()->json([
            'message' => $message,
            'data' => [
                "folder" => [
                    'id' => $folder->id,
                    'name' => $folder->name,
                ]
            ]
        ]);
    }

    public function restore(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'exists:folders,id'
        ], [
            'id.exists' => __('messages.exists', ['attribute' => 'Folder'])
        ]);

        if ($validator->fails())
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 400);

        $folder = Folder::withTrashed()->find($request->id);

        if (!$folder)
            return response()->json([
                'success' => false,
                'message' => __('messages.not_found', ['attribute' => 'Folder'])
            ], 404);

        $this->checkPermissionFolder('delete', $folder->id, true);

        $user = $request->user();

        if (!$user->hasRole('admin') && !$user->hasPermissionTo("Folder.{$folder->id}.delete")) {
            abort(response()->json([
                'success' => false,
                'message' => __('messages.permission_denied')
            ], 403));
        }

        $folder->restore();
        $folder->restoreChildren();

        return response()->json([
            'success' => true,
            'message' => 'Thư mục đã được khôi phục!',
            'data' => [
                'folder' => $folder
            ]
        ]);
    }

    public function deletePermanently(Request $request)
    {
        $this->checkPermissionFolder('delete');

        $folder = Folder::withTrashed()
            ->with('files', 'children')
            ->find($request->id);

        if (!$folder)
            return response()->json([
                'success' => false,
                'message' => __('messages.not_found', ['attribute' => 'Thư mục'])
            ], 404);

        $folder->deleteFilesInStorage();

        $folder->forceDelete();

        return response()->json([
            'message' => __('messages.deleted_permanently', ['attribute' => 'Thư mục']),
            'data' => [
                "folder" => [
                    'id' => $folder->id,
                    'name' => $folder->name,
                ]
            ]
        ]);
    }

    public function searchFile(Request $request)
    {
        $this->checkPermissionFolder('read');

        $folder = Folder::find($request->id);

        if (!$folder)
            return response()->json([
                'success' => false,
                'message' => __('messages.not_found', ['attribute' => 'Thư mục'])
            ], 404);

        $files = $folder->files()
            ->where('name', 'like', "%{$request->name}%")
            ->orWhere('path', 'like', "%{$request->name}%")
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'files' => $files
            ]
        ]);
    }

    public function setShortcut(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'folder_id' => 'required|exists:folders,id',
            'shortcut' => 'required|boolean',
        ], [
            'folder_id.required' => __('messages.required', ['attribute' => 'folder id']),
            'folder_id.exists' => __('messages.exists', ['attribute' => 'Thư mục']),
            'shortcut.required' => __('messages.required', ['attribute' => 'shortcut']),
            'shortcut.boolean' => __('messages.boolean', ['attribute' => 'shortcut']),
        ]);

        if ($validator->fails())
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 400);

        $folder = Folder::find($request->folder_id);

        if (!$folder)
            return response()->json([
                'success' => false,
                'message' => __('messages.not_found', ['attribute' => 'Thư mục'])
            ], 404);

        if ($request->shortcut) {

            $folderShortcut = FolderShortcut::where('folder_id', $folder->id)
                ->where('user_id', $request->user()->id)
                ->first();

            if ($folderShortcut)
                return response()->json([
                    'message' => __('messages.unique', ['attribute' => 'Folder shortcut']),
                    'success' => false,
                ], 400);

            $folderShortcut = new FolderShortcut;
            $folderShortcut->folder_id = $folder->id;
            $folderShortcut->user_id = $request->user()->id;
            $folderShortcut->save();
        } else {

            $folderShortcut = FolderShortcut::where('folder_id', $folder->id)
                ->where('user_id', $request->user()->id)
                ->first();

            if (!$folderShortcut)
                return response()->json([
                    'message' => __('messages.not_found', ['attribute' => 'Folder shortcut']),
                    'success' => false,
                ], 404);

            FolderShortcut::where('folder_id', $folder->id)
                ->where('user_id', $request->user()->id)
                ->delete();
        }

        $message = $request->shortcut ?
            __('messages.set_to_shortcut', ['attribute' => 'thư mục']) :
            __('messages.set_to_not_shortcut', ['attribute' => 'thư mục']);

        return response()->json([
            'message' => $message,
            'success' => true,
        ]);
    }
}
