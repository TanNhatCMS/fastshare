<?php

namespace App\Http\Controllers;

use App\Models\Folder;
use App\Models\User;
use App\Rules\CommaSeparatedArray;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AuthorizeController extends BaseAPIController
{
    protected array $request_data;
    protected Request $request;

    public function __construct(Request $request)
    {
        $this->request_data = $request->all();

        $this->request = $request;
    }

    public function rules(bool $id = false): array
    {
        $rules = [
            'authorization' => 'required|array',
            'authorization.*.user_id' => 'required|exists:users,id',
            'authorization.*.role' => ['required', new CommaSeparatedArray(Folder::ROLES)],
            'authorization.*.folder_id' => 'required|exists:folders,id',
        ];

        if ($id) {
            $rules = [
                'folder_id' => 'required|exists:folders,id',
            ];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'authorization.required' => __('messages.required', ['attribute' => 'authorization']),
            'authorization.array' => __('messages.array', ['attribute' => 'authorization']),
            'authorization.*.user_id.required' => __('messages.required', ['attribute' => 'user_id']),
            'authorization.*.user_id.exists' => __('messages.exists', ['attribute' => 'user_id']),
            'authorization.*.role.required' => __('messages.required', ['attribute' => 'role']),
            'authorization.*.role.in' => __('messages.in', ['attribute' => 'role']),
            'authorization.*.folder_id.required' => __('messages.required', ['attribute' => 'Thư mục']),
            'authorization.*.folder_id.exists' => __('messages.exists', ['attribute' => 'Thư mục']),
            'folder_id.required' => __('messages.required', ['attribute' => 'Thư mục']),
            'folder_id.exists' => __('messages.exists', ['attribute' => 'Thư mục']),
        ];
    }

    public function addUserToFolder(Request $request)
    {
        $this->validatorApi();

        $authorization = $request->authorization;

        $resultMsg = [];

        try {
            foreach ($authorization as $item) {
                $user = User::find($item['user_id']);

                $roles = $item['role'];

                if (gettype($item['role']) === 'string') {
                    $roles = explode(',', $item['role']);
                }

                $role = 'viewer';

                if (in_array('admin', $roles)) {
                    $role = 'admin';
                } elseif (in_array('editor', $roles)) {
                    $role = 'editor';
                }

                $folder = Folder::find($item['folder_id']);

                $folder->assignRolesToUser($user, $role);

                // $role = "Folder." . $item['folder_id'] . ".{$role}"; // "Folder.{folder_id}.{role}"

                // $user->assignRole($role);

                $resultMsg[] = [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                    ],
                    'role' => $item['role'],
                    'folder' => $item['folder_id'],
                ];
            }
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Users has been added to folders successfully.',
            'data' => $resultMsg,
        ]);
    }

    public function removeUserFromFolder(Request $request)
    {
        $this->validatorApi();

        $authorization = $request->authorization;

        $resultMsg = [];

        try {
            foreach ($authorization as $item) {
                $user = User::find($item['user_id']);

                $roles = $item['role'];

                if (gettype($item['role']) === 'string') {
                    $roles = explode(',', $item['role']);
                }

                foreach ($roles as $role) {
                    // $role = "Folder." . $item['folder_id'] . ".{$role}";

                    // $user->removeRole($role);

                    $folder = Folder::find($item['folder_id']);

                    $folder->removeRolesFromUser($user, $role);
                }

                $resultMsg[] = [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                    ],
                    'role' => $item['role'],
                    'folder' => $item['folder_id'],
                ];
            }
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => __('messages.user_removed_from_folder'),
            'data' => $resultMsg,
        ]);
    }

    public function listUserInFolder(Request $request)
    {
        $this->validatorApi(true);

        $folder = Folder::withTrashed()->where('id', $request->folder_id)->with('children')->first();

        if (! $folder) {
            return response()->json([
                'success' => false,
                'message' => 'Folder not found.',
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $folder->members(),
        ]);
    }

    public function listFoldersOfUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
        ], [
            'user_id.required' => __('messages.required', ['attribute' => 'user_id']),
            'user_id.exists' => __('messages.exists', ['attribute' => 'user_id']),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ]);
        }

        $user = User::find($request->user_id);

        if ($user->hasRole('admin')) {
            return response()->json([
                'success' => true,
                'message' => 'Admin có quyền tất cả thư mục.',
            ]);
        }

        $roles = $user->roles;

        //get all model_has_role where user_id = $user->id
        $model_has_roles = DB::table('model_has_roles')
            ->where('model_id', $user->id)
            ->where('model_type', 'App\Models\User')
            ->get();

        //add created_at $model_has_roles to $role in $_roles
        foreach ($model_has_roles as $model_has_role) {
            foreach ($roles as $role) {
                if ($model_has_role->role_id == $role->id) {
                    $role->pivot->created_at = $model_has_role->created_at;
                }
            }
        }
        //chỉ giữ lại name và created_at
        $roles = $roles->map(function ($role) {
            return [
                'name' => $role->name,
                'created_at' => $role->pivot->created_at,
            ];
        });

        $folders = [];
        $folder_ids = [];

        foreach ($roles as $_role) {
            $role = explode('.', $_role['name']);
            $folder_id = $role[1];

            if (! in_array($folder_id, $folder_ids)) {
                $folder = Folder::select(['id', 'name', 'folder_id', 'permission'])
                    ->find($folder_id);

                if (! $folder) {
                    continue;
                }

                $folder['role'] = $role[2];
                $folder['model_role_created_at'] = $_role['created_at'];
                $folder['permission'] = $folder->folderPermission($user);
                $folder['breadcrumb'] = $folder->displayBreadcrumb();

                $folders[] = $folder;
                $folder_ids[] = $folder_id;
                $parent_id = $folder['parent']['id'];
                unset($folder['parent']);
                $folder['parent']['id'] = $parent_id;
            }
        }

        //sắp xếp lại theo model_role_created_at
        usort($folders, function ($a, $b) {
            return $a['model_role_created_at'] <=> $b['model_role_created_at'];
        });

        // sắp xếp lại theo folder role thứ tự admin > editor > viewer
        usort($folders, function ($a, $b) {
            return $a['role'] <=> $b['role'];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'folders' => $folders,
            ],
        ]);
    }

    public function foldersHome()
    {
        try {
            $folders = Folder::getAllChildren(1);

            return response()->json([
                'success' => true,
                'data' => $folders,
                'message' => 'Folders home.',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
