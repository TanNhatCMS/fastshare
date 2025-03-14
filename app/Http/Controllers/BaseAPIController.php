<?php

namespace App\Http\Controllers;

use App\Models\Folder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

abstract class BaseAPIController extends Controller
{
    protected array $request_data;
    protected Request $request;

    abstract function rules(bool $id = false): array;

    abstract function messages(): array;

    protected function validatorApi(bool $edit = false): ?bool
    {
        $validator = Validator::make($this->request_data, $this->rules($edit), $this->messages());

        if ($validator->fails())
            abort(response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 400));

        return true;
    }

    protected function requireID($id, array $ignore = []): void
    {
        /** @var Request $request */
        $request = request();

        $routeCurrent = $request->route()->getName();

        if (in_array($routeCurrent, $ignore)) return;

        if ($id === null) abort(response()->json([
            'success' => false,
            'message' => __('messages.required', ['attribute' => 'ID'])
        ], 400));
    }

    protected function checkPermissionFolder(string $permission, $folder_id = null, $allow_trash = false): void
    {
        $folder_id = $folder_id === null ?
            $this->request_data['id'] :
            $folder_id;
        if ($this->request->user()?->hasRole('admin')) return;
        $user = $this->request->user();
        $folder = Folder::find($folder_id);
        if ($allow_trash) $folder = Folder::withTrashed()->find($folder_id);
        if (!$folder) {
             abort(response()->json([
                'success' => false,
                'message' => __('messages.not_found', ['attribute' => 'Folder'])
            ], 404));
        }
        if ( $folder->hasRole($user,'admin')) return;
        $hasPermission = $folder->hasPermission($user, $permission);
        if (!$hasPermission) {
             abort(response()->json([
                'success' => false,
                'message' => __('messages.permission_denied')
            ], 403));
        }

    }
}
