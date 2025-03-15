<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AuthorizeController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\FolderController;
use Illuminate\Support\Facades\Route;

Route::group([
    'middleware' => 'api',
    'prefix'     => 'auth',
], function () {
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('auth:api')
        ->name('register');

    Route::post('/login', [AuthController::class, 'login'])
        ->name('login');

    Route::post('/logout', [AuthController::class, 'logout'])
        ->middleware('auth:api')
        ->name('logout');

    Route::post('/refresh', [AuthController::class, 'refresh'])
        ->middleware('auth:api')
        ->name('refresh');

    Route::post('/profile', [AuthController::class, 'profile'])
        ->middleware('auth:api')
        ->name('profile');

    Route::post('my-folder', [AuthController::class, 'myFolders'])
        ->middleware('auth:api')
        ->name('folders.my');

    Route::post('my-shortcut', [AuthController::class, 'myShortcuts'])
        ->middleware('auth:api')
        ->name('folders.my-shortcut');
});

Route::group([
    'middleware' => ['api', 'auth:api'],
], function () {
    Route::post('folders', [FolderController::class, 'index'])
        ->name('folders.index');

    Route::post('folders/create', [FolderController::class, 'store'])
        ->name('folders.store');

    Route::post('folders/update', [FolderController::class, 'update'])
        ->name('folders.update');

    Route::post('folders/delete', [FolderController::class, 'destroy'])
        ->name('folders.destroy');

    Route::post('folders/restore', [FolderController::class, 'restore'])
        ->name('folders.restore');

    Route::post('folders/delete-permanently', [FolderController::class, 'deletePermanently'])
        ->name('folders.delete-permanently');

    Route::post('folders/file-search', [FolderController::class, 'searchFile'])
        ->name('folders.file-search');

    Route::post('folders/set-shortcut', [FolderController::class, 'setShortcut'])
        ->name('folders.set-shortcut');
});

Route::group([
    'middleware' => ['api', 'auth:api'],
], function () {
    Route::post('file', [FileController::class, 'index'])
        ->name('file.index');

    Route::post('file/upload', [FileController::class, 'store'])
        ->name('file.upload');

    Route::post('file/update', [FileController::class, 'update'])
        ->name('file.update');

    Route::post('file/delete', [FileController::class, 'destroy'])
        ->name('file.destroy');

    Route::post('trash', [AuthController::class, 'trash'])
        ->name('file.trash');

    Route::post('file/restore', [FileController::class, 'restore'])
        ->name('file.restore');

    Route::post('file/delete-permanently', [FileController::class, 'deletePermanently'])
        ->name('file.delete-permanently');

    Route::post('file/search', [AuthController::class, 'searchFile'])
        ->name('file.search');

    Route::post('getfile', [FileController::class, 'getFileUrl'])
        ->middleware('auth:api')
        ->name('file.get-url');

    Route::post('file/set-shortcut', [FileController::class, 'setShortcut'])
        ->name('file.set-shortcut');
});

Route::group([
    'middleware' => ['api', 'auth:api', 'role:admin'],
    'prefix'     => 'admin',
], function () {
    Route::prefix('folder')->group(function () {
        Route::post('add-user', [AuthorizeController::class, 'addUserToFolder'])
            ->name('admin.folder.add-user');

        Route::post('remove-user', [AuthorizeController::class, 'removeUserFromFolder'])
            ->name('admin.folder.remove-user');

        Route::post('list-user', [AuthorizeController::class, 'listUserInFolder'])
            ->name('admin.folder.list-user');

        Route::post('home', [AuthorizeController::class, 'foldersHome'])
            ->name('admin.folder.home');
    });

    Route::post('list-user', [AuthController::class, 'listUsers'])
        ->middleware('auth:api')
        ->name('admin.list-user');

    Route::post('list-folder-of-user', [AuthorizeController::class, 'listFoldersOfUser'])
        ->name('admin.folder.list-folder-of-user');

    Route::post('edit-user', [AuthController::class, 'editUser'])
        ->middleware('auth:api')
        ->name('admin.edit-user');

    Route::post('delete-user', [AuthController::class, 'deleteUser'])
        ->middleware('auth:api')
        ->name('admin.delete-user');
});
