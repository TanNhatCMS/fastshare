<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    /**
     * Register a User.
     *
     * @return JsonResponse
     */
    public function register(Request $request)
    {
        if (! $request->user()->hasRole('admin')) {
            return response()->json([
                'success' => false,
                'message' => __('messages.permission_denied'),
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|min:3|max:50|regex:/^[\pL\s]+$/u|unique:users,name',
            'username' => 'required|unique:users|min:4|max:32|regex:/^[a-zA-Z0-9_.]+$/',
            'password_confirmation' => 'required',
            'password' => 'required|confirmed|min:4|max:64',
        ], [
            'name.required' => __('messages.required', ['attribute' => 'Họ và tên']),
            'name.regex' => __('messages.regex', ['attribute' => 'Họ và tên']),
            'name.unique' => __('messages.unique', ['attribute' => 'Họ và tên']),
            'name.min' => __('messages.min', ['attribute' => 'Họ và tên', 'min' => 3]),
            'name.max' => __('messages.max', ['attribute' => 'Họ và tên', 'max' => 50]),
            'username.required' => __('messages.required', ['attribute' => 'Tài khoản']),
            'username.unique' => __('messages.unique', ['attribute' => 'Tài khoản']),
            'username.min' => __('messages.min', ['attribute' => 'Tài khoản', 'min' => 4]),
            'username.max' => __('messages.max', ['attribute' => 'Tài khoản', 'max' => 32]),
            'username.regex' => __('messages.regex', ['attribute' => 'Tài khoản']),
            'password.required' => __('messages.required', ['attribute' => 'Mật khẩu']),
            'password_confirmation.required' => __('messages.required', ['attribute' => 'Xác nhận mật khẩu']),
            'password.confirmed' => __('messages.confirmed', ['attribute' => 'Xác nhận mật khẩu']),
            'password.min' => __('messages.min', ['attribute' => 'Mật khẩu', 'min' => 4]),
            'password.max' => __('messages.max', ['attribute' => 'Mật khẩu', 'max' => 64]),
        ]);

        if ($validator->fails()) { // kiểm tra xem có lỗi hay không có ít nhất một lỗi sẽ trả về false còn khong trả về true
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 400);
        }

        $last_user_id = User::orderBy('id', 'DESC')->first()->id;

        $user = new User();
        $user->id = $last_user_id + 1;
        $user->name = $request->name;
        $user->username = $request->username;
        $user->password = bcrypt($request->password);
        $user->save();
        //attempt để kiểm tra username và password
        //Nếu thông tin đăng nhập chính xác, phương thức attempt sẽ trả về một mã thông báo (token) ngược lại trả về false
        $token = auth()->attempt($request->only('username', 'password'));

        return $this->respondWithToken($token, [
            'user' => $user,
        ], 201);
    }

    /**
     * Get a JWT via given credentials.
     *
     * @return JsonResponse
     */
    public function login()
    {
        $credentials = request(['username', 'password']);

        if (! $token = auth()->attempt($credentials)) {
            return response()->json([
                'success' => false,
                'message' => __('messages.credentials'),
            ], 401);
        }

        return $this->respondWithToken($token, [
            'user' => auth()->user(),
        ]);
    }

    /**
     * Get the authenticated User.
     *
     * @return JsonResponse
     */
    public function profile()
    {
        return response()->json(auth()->user());
    }

    /**
     * Log the user out (Invalidate the token).
     *
     * @return JsonResponse
     */
    public function logout()
    {
        auth()->logout();

        return response()->json(['message' => 'Đăng xuất thành công']);
    }

    /**
     * Refresh a token.
     *
     * @return JsonResponse
     */
    public function refresh()
    {
        /** JWTGuard */
        $auth = auth();

        return $this->respondWithToken($auth->refresh());
    }

    /**
     * Get the token array structure.
     *
     * @param  string  $token
     * @param  array  $data
     * @param  int  $stt_code
     * @return JsonResponse
     */
    protected function respondWithToken(string $token, array $data = [], int $stt_code = 200)
    {
        try {
            $auth = auth();

            return response()->json([
                'success' => true,
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => $auth->factory()->getTTL() * 60,
                'data' => $data,
            ], $stt_code);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Không tạo được mã token.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function myFolders(Request $request)
    {
        $user = $request->user();
        if ($user->hasRole('admin')) {
            $folders = Folder::select(['id', 'name', 'folder_id', 'permission', 'created_at', 'updated_at'])->paginate(10);
            $folders->getCollection()->transform(function ($folder) use ($user) {
                $folder->permission = $folder->folderPermission($user);
                $folder->role = 'Super admin';
                $folder->breadcrumb = $folder->displayBreadcrumb();

                return $folder;
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'folders' => $folders,
                ],
            ]);
        }

        $roles = $request->user()->getRoleNames();
        $folders = [];
        $folders_ids = [];
        $list_roles = [];
        foreach ($roles as $role) {
            $role = explode('.', $role);
            if (count($role) < 3) {
                continue;
            }
            $folder_id = $role[1];
            if (! in_array($folder_id, $folders_ids)) {
                $list_roles[] = [
                    'id' => $folder_id,
                    'name' => $role[2],
                ];
                $folders_ids[] = $folder_id;
            }
        }
        $folder_ids = [];
        $setBreadcrumb = function ($folderItem) use ($list_roles) {
            $folder = $folderItem;
            $breadcrumb = [];
            while ($folder->parent) {
                if (in_array($folder->id, array_column($list_roles, 'id'))) {
                    $breadcrumb[] = $folder->name;
                }
                $folder = $folder->parent;
            }

            return array_reverse($breadcrumb);
        };
        foreach ($list_roles as $role) {
            $folder_id = $role['id'];
            if (! in_array($folder_id, $folder_ids)) {
                $folder = Folder::select(['id', 'name', 'folder_id', 'permission', 'created_at', 'updated_at'])
                    ->find($folder_id);
                if (! $folder) {
                    continue;
                }
                $folder['role'] = $role['name'];
                $folder['breadcrumb'] = $setBreadcrumb($folder);
                $folder['permission'] = $folder->folderPermission($request->user());
                $folders[] = $folder;
                $folder_ids[] = $folder_id;
            }
        }
        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $perPage = 10;
        $offset = ($currentPage - 1) * $perPage;
        $items = array_slice($folders, $offset, $perPage, true);
        $paginator = new LengthAwarePaginator(
            $items,
            count($folders),
            $perPage,
            $currentPage,
            ['path' => Paginator::resolveCurrentPath()]
        );

        return response()->json([
            'success' => true,
            'data' => [
                'folders' => $paginator,
            ],
        ]);
    }

    public function myShortcuts(Request $request)
    {
        $user = $request->user();

        $folders = $user->folderShortcuts()
            ->with('folder')
            ->get()
            ->filter(fn ($folderShortcut) => $folderShortcut->folder !== null)
            ->map(fn ($folder) => [
                'id' => $folder->folder->id,
                'name' => $folder->folder->name,
                'permission' => $folder->folder->folderPermission($user),
                'deleted_at' => $folder->folder->deleted_at,
                'created_at' => $folder->folder->created_at,
                'updated_at' => $folder->folder->updated_at,
            ])
            ->values();

        $files = $user->fileShortcuts()
            ->with('file')
            ->get()
            ->filter(fn ($fileShortcut) => $fileShortcut->file !== null)
            ->map(fn ($file) => [
                'id' => $file->file->id,
                'name' => $file->file->name,
                'path' => $file->file->path,
                'upload_by' => $user->name,
                'folder_id' => $file->file->folder_id,
                'size' => $file->file->size,
                'type' => $file->file->type,
                'deleted_at' => $file->file->deleted_at,
                'created_at' => $file->file->created_at,
                'updated_at' => $file->file->updated_at,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'folders' => $folders,
                'files' => $files,
            ],
        ]);
    }

    public function trash(Request $request)
    {
        $user = $request->user();
        if ($user->hasRole('admin')) {
            $files = [];
            $folders = [];
            $all_folder = Folder::withTrashed()->get();
            foreach ($all_folder as $folder) {
                if ($folder->trashed()) {
                    $folders[] = $folder;
                    continue;
                }
                $filesTrash = File::onlyTrashed()->where('folder_id', $folder->id)->get();
                if (! $filesTrash->isEmpty()) {
                    array_push($files, ...$filesTrash);
                }
            }
            $folders = collect($folders);
            $folders = $folders->filter(
                fn ($folder) => ! $folders->contains('id', $folder->folder_id)
            )->values()
                ->toArray();

            return response()->json([
                'success' => true,
                'data' => [
                    'trash' => [
                        'files' => $files,
                        'folders' => $folders,
                    ],
                ],
            ]);
        }
        $roles = $user->getRoleNames();
        $files = [];
        $folders = [];
        foreach ($roles as $role) {
            $role = explode('.', $role);
            $folder_id = $role[1];
            $folder = Folder::withTrashed()->where('id', $folder_id)->first();
            if (! $folder) {
                continue;
            }
            if ($folder->trashed() && ! in_array($folder, $folders)) {
                $folders[] = $folder;
                continue;
            }
            $_files = File::onlyTrashed()->where('folder_id', $folder_id)->get();
            foreach ($_files as $file) {
                if (! in_array($file, $files)) {
                    $files[] = $file;
                }
            }
        }

        $folders = collect($folders)->map(function ($folder) use ($user) {
            $folder->permission = $folder->folderPermission($user);

            return $folder;
        });

        $folders = $folders->filter(
            fn ($folder) => ! $folders->contains('id', $folder->folder_id)
        )->values()
            ->toArray();

        return response()->json([
            'success' => true,
            'data' => [
                'trash' => [
                    'files' => $files,
                    'folders' => $folders,
                ],
            ],
        ]);
    }

    public function listUsers(Request $request)
    {
        try {
            if (! $request->user()->hasRole('admin')) {
                return response()->json([
                    'success' => false,
                    'message' => __('messages.permission_denied'),
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'users' => $request->get_all ? User::all() : User::paginate(10),
                ],
                'message' => 'Lấy danh sách người dùng thành công',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function editUser(Request $request)
    {
        if (! $request->user()->hasRole('admin')) {
            return response()->json([
                'success' => false,
                'message' => __('messages.permission_denied'),
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:users,id',
            'name' => 'required|min:3|max:50|regex:/^[\pL\s]+$/u|unique:users,name,'.$request->id,
            'username' => 'required|min:4|max:32|regex:/^[a-zA-Z0-9_.]+$/|unique:users,username,'.$request->id,
            'password' => 'nullable|min:4|max:64',
        ], [
            'id.required' => __('messages.required', ['attribute' => 'ID']),
            'id.exists' => __('messages.not_found', ['attribute' => 'Người dùng']),
            'name.min' => __('messages.min', ['attribute' => 'Họ và tên', 'min' => 3]),
            'name.max' => __('messages.max', ['attribute' => 'Họ và tên', 'max' => 50]),
            'name.regex' => __('messages.regex', ['attribute' => 'Họ và tên']),
            'name.required' => __('messages.required', ['attribute' => 'Họ và tên']),
            'name.unique' => __('messages.unique', ['attribute' => 'Họ và tên']),
            'username.min' => __('messages.min', ['attribute' => 'Tài khoản', 'min' => 4]),
            'username.max' => __('messages.max', ['attribute' => 'Tài khoản', 'max' => 32]),
            'username.unique' => __('messages.unique', ['attribute' => 'Tài khoản']),
            'username.regex' => __('messages.regex', ['attribute' => 'Tài khoản']),
            'username.required' => __('messages.required', ['attribute' => 'Tài khoản']),
            'password.min' => __('messages.min', ['attribute' => 'Mật khẩu', 'min' => 4]),
            'password.max' => __('messages.max', ['attribute' => 'Mật khẩu', 'max' => 64]),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 400);
        }

        $user = User::find($request->id);

        if ($request->filled('username')) {
            $user->username = $request->username;
        }

        if ($request->filled('password')) {
            $user->password = bcrypt($request->password);
        }

        if ($request->filled('name')) {
            $user->name = $request->name;
        }

        if ($user->hasRole('admin')) {
            unset($user->username);
        }

        $user->save();

        return response()->json([
            'success' => true,
            'message' => __('messages.updated', ['attribute' => 'Người dùng']),
            'data' => [
                'user' => $user,
            ],
        ]);
    }

    public function deleteUser(Request $request)
    {
        if (! $request->user()->hasRole('admin')) {
            return response()->json([
                'success' => false,
                'message' => __('messages.permission_denied'),
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:users,id',
        ], [
            'id.required' => __('messages.required', ['attribute' => 'ID']),
            'id.exists' => __('messages.not_found', ['attribute' => 'User']),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 400);
        }

        $user = User::find($request->id);

        if ($user->hasRole('admin')) {
            return response()->json([
                'success' => false,
                'message' => __('messages.cannot_delete', ['attribute' => 'Admin']),
            ], 400);
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => __('messages.deleted', ['attribute' => 'Người dùng']),
            'data' => [
                'user' => $user,
            ],
        ]);
    }

    public function searchFile(Request $request)
    {
        $user = $request->user();
        if ($user->hasRole('admin')) {
            $files = $request->is_trashed ?
                File::onlyTrashed()
                    ->where(
                        fn ($query) => $query->where('name', 'like', "%{$request->name}%")
                        ->orWhere('path', 'like', "%{$request->name}%")
                    )->get() :
                File::where('name', 'like', "%{$request->name}%")
                    ->orWhere('path', 'like', "%{$request->name}%")
                    ->get();

            $files = $files->map(function ($file) {
                $file->upload_by = $file->user->name;
                unset($file->user);

                return $file;
            });

            $folders = $request->is_trashed ?
                Folder::onlyTrashed()
                    ->where('name', 'like', "%{$request->name}%")
                    ->get() :
                Folder::where('name', 'like', "%{$request->name}%")
                    ->get();

            $folders = $folders->map(function ($folder) use ($user) {
                $folder->permission = $folder->folderPermission($user);

                return $folder;
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'files' => $files,
                    'folders' => $folders,
                ],
            ]);
        }

        $roles = $user->getRoleNames();

        $files = [];
        $folders = [];

        foreach ($roles as $role) {
            $role = explode('.', $role);
            $folder_id = $role[1];

            $folder = $request->is_trashed ?
                Folder::withTrashed()->find($folder_id) :
                Folder::find($folder_id);

            if (! $folder) {
                continue;
            }

            if (! str_contains($folder->permission, 'read')) {
                continue;
            }

            $file_result = $request->is_trashed ?
                File::onlyTrashed()
                    ->where('folder_id', $folder_id)
                    ->where(
                        fn ($query) => $query
                        ->where('name', 'like', "%{$request->name}%")
                        ->orWhere('path', 'like', "%{$request->name}%")
                    )->get() :
                File::where('folder_id', $folder_id)
                    ->where(
                        fn ($query) => $query
                        ->where('name', 'like', "%{$request->name}%")
                        ->orWhere('path', 'like', "%{$request->name}%")
                    )->get();

            $folderSearchName = strtolower(str_replace(' ', '', removeAccents($folder->name)));
            $requestFolderSearchName = strtolower(str_replace(' ', '', removeAccents($request->name)));
            if (str_contains($folderSearchName, $requestFolderSearchName)) {
                $folder->permission = $folder->folderPermission($user);
                if (! in_array($folder->id, array_column($folders, 'id'))) {
                    if ($request->is_trashed) {
                        if ($folder->deleted_at !== null) {
                            $folders[] = $folder;
                        }
                    } else {
                        $folders[] = $folder;
                    }
                }
            }
            if (! $file_result->isEmpty()) {
                $file_result = $file_result->map(function ($file) {
                    $file->upload_by = $file->user->name;
                    unset($file->user);

                    return $file;
                });
                array_push($files, ...$file_result);
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'files' => $files,
                'folders' => $folders,
            ],
        ]);
    }
}
