<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\FileShortcut;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FileController extends BaseAPIController implements HasMiddleware
{
    protected array $request_data;
    protected Request $request;

    public function __construct(Request $request)
    {
        $this->requireID($request->id, [
            'file.upload',
            'file.view',
            'file.get-url',
            'file.set-shortcut',
        ]);

        $this->request_data = $request->all();

        $this->request = $request;
    }

    public static function middleware(): array
    {
        $file_id = request('id');

        if ($file_id === null) {
            return [];
        }

        $folder_id = File::find($file_id)?->folder_id;

        if ($folder_id === null) {
            return [];
        }

        return [
            new Middleware(middleware: "permission:Folder.{$folder_id}.create", only: ['store']),
            new Middleware(middleware: "permission:Folder.{$folder_id}.read", only: ['index']),
            new Middleware(middleware: "permission:Folder.{$folder_id}.update", only: ['update']),
            new Middleware(middleware: "permission:Folder.{$folder_id}.delete", only: ['destroy']),
            new Middleware(middleware: "permission:Folder.{$folder_id}.delete", only: ['deletePermanently']),
        ];
    }

    public function rules(bool $edit = false): array
    {
        $rules = [
            'name' => [
                'nullable',
                Rule::unique('files')->where(function ($query) {
                    return $query->where('folder_id', $this->request->folder_id);
                }),
            ],
            'path'      => 'nullable',
            'type'      => 'required|in:'.implode(',', File::TYPE),
            'size'      => 'nullable|numeric',
            'folder_id' => 'required|exists:folders,id',
        ];

        if ($edit) {
            $rules = [
                'id' => 'required|exists:files,id',
            ];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'name.required'      => __('messages.required', ['attribute' => 'name']),
            'name.unique'        => __('messages.unique', ['attribute' => 'Name']),
            'path.required'      => __('messages.required', ['attribute' => 'path']),
            'type.required'      => __('messages.required', ['attribute' => 'type']),
            'type.in'            => __('messages.in', ['attribute' => 'Type']),
            'size.numeric'       => __('messages.numeric', ['attribute' => 'Size']),
            'folder_id.required' => __('messages.required', ['attribute' => 'folder']),
            'folder_id.exists'   => __('messages.exists', ['attribute' => 'Folder']),
            'id.required'        => __('messages.required', ['attribute' => 'file']),
            'id.exists'          => __('messages.exists', ['attribute' => 'File']),
        ];
    }

    public function index(Request $request)
    {
        $file = File::with('folder')
            ->with([
                'user' => fn ($query) => $query->select('name', 'id'),
            ])
            ->find($request->id);

        if (!$file) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy tập tin',
            ], 404);
        }

        $this->checkPermissionFolder('read', $file->folder_id);

        $file->upload_by = $file->user->name;
        unset($file->user);

        return response()->json([
            'success' => true,
            'data'    => [
                'file' => $file,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->validatorApi();

        $this->checkPermissionFolder('create', $request->folder_id);

        $user = $request->user();

        if (!$user->hasRole('admin') && !$user->hasPermissionTo("Folder.{$request->folder_id}.create")) {
            abort(response()->json([
                'success' => false,
                'message' => __('messages.permission_denied'),
            ], 403));
        }

        if (!$request->has('file') && !$request->has('path')) {
            return response()->json([
                'success' => false,
                'message' => __('messages.required', ['attribute' => 'File or path']),
            ], 400);
        }

        try {
            $files = [];

            // Kiểm tra xem có tệp nào không
            if ($request->hasFile('files')) {
                foreach ($request->file('files') as $fileUpload) {
                    $originalName = $fileUpload->getClientOriginalName();
                    // Tạo tên file mới  \
                    $extension = $fileUpload->getClientOriginalExtension();
                    $baseName = pathinfo($originalName, PATHINFO_FILENAME);
                    $newName = $originalName; // Khởi tạo tên file mới với tên gốc
                    $counter = 1; // default 1

                    // Kiểm tra xem file đã tồn tại chưa
                    while (Storage::exists('files/'.$newName)) {
                        // Nếu đã tồn tại, tạo tên mới với số đếm
                        $newName = $baseName.'('.$counter.')'.'.'.$extension;
                        $counter++;
                    }

                    $path = $fileUpload->storeAs('files', $newName);
                    $size = $fileUpload->getSize();

                    // Kiểm tra loại MIME
                    if (
                        !in_array($fileUpload->getMimeType(), File::IMAGE_MIME) &&
                        !in_array($fileUpload->getMimeType(), File::DOCUMENT_MIME)
                    ) {
                        return response()->json([
                            'success' => false,
                            'message' => 'File chỉ chấp nhận các định dạng Hình ảnh, PDF, Word, Excel, Powerpoint',
                        ], 400);
                    }

                    $type = $this->determineFileType($fileUpload->getMimeType());

                    $file = new File();
                    $file->name = $request->name ?? $newName;
                    $file->path = $path;
                    $file->size = $size;
                    $file->type = $type;
                    $file->folder_id = $request->folder_id;
                    $file->user_id = $user->id;
                    $file->save();

                    $files[] = $file;
                }
            } else {
                // Xử lý khi có đường dẫn URL
                $path = $request->path; // Lưu giá trị vào biến
                if (!filter_var($path, FILTER_VALIDATE_URL)) {
                    return response()->json([
                        'success' => false,
                        'message' => __('messages.invalid_type', [
                            'attribute' => 'Path',
                            'type'      => 'URL (https://domain.com)',
                        ]),
                    ], 400);
                }

                // Tạo bản ghi cho đường dẫn URL
                $file = new File();
                $file->name = $request->name;
                $file->path = $path;
                $file->type = 'link';
                $file->size = $request->size ?? 0;
                $file->folder_id = $request->folder_id;
                $file->user_id = $user->id;
                $file->save();

                $files[] = $file;
            }
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'message' => 'Tải lên thành công!',
            'data'    => [
                'files' => $files,
            ],
        ]);
    }

    private function determineFileType($mimeType)
    {
        if (in_array($mimeType, File::IMAGE_MIME)) {
            return 'image';
        } elseif (in_array($mimeType, File::VIDEO_MIME)) {
            return 'video';
        } elseif (in_array($mimeType, File::AUDIO_MIME)) {
            return 'audio';
        } elseif (in_array($mimeType, File::DOCUMENT_MIME)) {
            return 'document';
        } else {
            return 'file';
        }
    }

    public function update(Request $request)
    {
        $this->validatorApi(true);

        $file = File::find($request->id);

        $this->checkPermissionFolder('update', $file->folder_id);

        // move file to another folder
        if ($request->filled('folder_id') && $request->folder_id !== $file->folder_id) {
            $this->checkPermissionFolder('update', $request->folder_id);

            $hasPermission = $request->user()->hasPermissionTo("Folder.{$request->folder_id}.update");

            if (!$hasPermission && !$request->user()->hasRole('admin')) {
                return response()->json([
                    'success' => false,
                    'message' => __('messages.permission_denied'),
                ], 403);
            }
        }

        $fileCheck = File::where('id', '!=', $file->id)
            ->where('folder_id', $request->folder_id ?? $file->folder_id)
            ->where('name', $this->request_data['name'])
            ->exists();

        if ($fileCheck) {
            return response()->json([
                'success' => false,
                'message' => __('messages.unique', ['attribute' => 'Name']),
            ], 400);
        }

        if ($file->type === 'link') {
            if (!filter_var($request->path, FILTER_VALIDATE_URL)) {
                return response()->json([
                    'success' => false,
                    'message' => __('messages.invalid_type', [
                        'attribute' => 'Path',
                        'type'      => 'URL (https://domain.com)',
                    ]),
                ], 400);
            }
        }

        if (!$this->request_data['name']) {
            return response()->json([
                'success' => false,
                'message' => __('messages.required', ['attribute' => 'Name']),
            ], 400);
        }

        $file->update($this->request_data);

        return response()->json([
            'message' => 'Tập tin đã được cập nhật!',
            'data'    => [
                'file' => $file,
            ],
        ]);
    }

    public function destroy(Request $request)
    {
        $file = File::withTrashed()->find($request->id);

        if (!$file) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy tập tin',
            ], 404);
        }

        $this->checkPermissionFolder('delete', $file->folder_id);

        if ($file->deleted_at !== null) {
            $file->forceDelete();

            if ($file->path != null) {
                Storage::delete($file->path);
            }
        } else {
            $file->delete();
        }

        return response()->json([
            'message' => 'Đã xóa thành công!',
            'data'    => [
                'file' => $file,
            ],
        ]);
    }

    public function restore(Request $request)
    {
        $this->validatorApi(true);

        $file = File::withTrashed()->find($request->id);

        $this->checkPermissionFolder('delete', $file->folder_id);

        $user = $request->user();

        if (!$user->hasRole('admin') && !$user->hasPermissionTo("Folder.{$file->folder_id}.delete")) {
            abort(response()->json([
                'success' => false,
                'message' => __('messages.permission_denied'),
            ], 403));
        }

        $file->restore();

        return response()->json([
            'success' => true,
            'message' => 'Đã khôi phục tập tin!',
            'data'    => [
                'file' => $file,
            ],
        ]);
    }

    public function deletePermanently(Request $request)
    {
        $file = File::withTrashed()->find($request->id);

        if (!$file) {
            return response()->json([
                'success' => false,
                'message' => 'File not found',
            ], 404);
        }

        $this->checkPermissionFolder('delete', $file->folder_id);

        $file->forceDelete();

        if ($file->path != null) {
            Storage::delete($file->path);
        }

        return response()->json([
            'message' => 'File deleted!',
            'data'    => [
                'file' => $file,
            ],
        ]);
    }

    public function getFileUrl(Request $request)
    {
        $path = 'files/'.basename($request->path);

        $file = File::where('path', $path)->first();

        if (!$file) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy tập tin này!',
            ]);
        }

        $this->checkPermissionFolder('read', $file->folder_id);

        $roles = $request->user()->getRoleNames();

        $rolesFolder = $roles->map(function ($role) {
            $exRole = explode('.', $role);

            return $exRole[1] ?? null;
        })->filter()->unique()->values()->toArray();

        if (!in_array($file->folder_id, $rolesFolder) && !$request->user()->hasRole('admin')) {
            return response()->json([
                'success' => false,
                'message' => __('messages.permission_denied'),
            ], 403);
        }

        $url = URL::temporarySignedRoute(
            'file.view',
            now()->addMinutes($request->exp ?? 3), // 3 minutes
            [
                'path' => base64_encode($request->path),
            ]
        );

        return response()->json([
            'success' => true,
            'data'    => [
                'url' => $url,
            ],
        ]);
    }

    public function viewFile(string $path, Request $request)
    {
        $path = base64_decode($path);

        $path = "files/$path";

        if (!$request->hasValidSignature()) {
            abort(401);
        }

        $file = storage_path("app/$path");

        if (!file_exists($file)) {
            abort(404);
        }

        return response()->file($file, [
            'Content-Type'        => mime_content_type($file),
            'Content-Disposition' => 'filename='.basename($file),
            // 'Content-Disposition' => "document;filename=" . basename($file)
        ]);
    }

    public function setShortcut(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file_id'  => 'required|exists:files,id',
            'shortcut' => 'required|boolean',
        ], [
            'file_id.required'  => __('messages.required', ['attribute' => 'file id']),
            'file_id.exists'    => __('messages.exists', ['attribute' => 'File']),
            'shortcut.required' => __('messages.required', ['attribute' => 'shortcut']),
            'shortcut.boolean'  => __('messages.boolean', ['attribute' => 'shortcut']),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 400);
        }

        $file = File::find($request->file_id);

        if (!$file) {
            return response()->json([
                'success' => false,
                'message' => __('messages.not_found', ['attribute' => 'File']),
            ], 404);
        }

        if ($request->shortcut) {
            $fileShortcut = FileShortcut::where('file_id', $file->id)
                ->where('user_id', $request->user()->id)
                ->first();

            if ($fileShortcut) {
                return response()->json([
                    'message' => __('messages.unique', ['attribute' => 'File shortcut']),
                    'success' => false,
                ], 400);
            }

            $fileShortcut = new FileShortcut();
            $fileShortcut->file_id = $file->id;
            $fileShortcut->user_id = $request->user()->id;
            $fileShortcut->save();
        } else {
            $fileShortcut = FileShortcut::where('file_id', $file->id)
                ->where('user_id', $request->user()->id)
                ->first();

            if (!$fileShortcut) {
                return response()->json([
                    'message' => __('messages.not_found', ['attribute' => 'File shortcut']),
                    'success' => false,
                ], 404);
            }

            FileShortcut::where('file_id', $file->id)
                ->where('user_id', $request->user()->id)
                ->delete();
        }

        $message = $request->shortcut ?
            __('messages.set_to_shortcut', ['attribute' => 'tập tin']) :
            __('messages.set_to_not_shortcut', ['attribute' => 'tập tin']);

        return response()->json([
            'message' => $message,
            'success' => true,
        ]);
    }
}
