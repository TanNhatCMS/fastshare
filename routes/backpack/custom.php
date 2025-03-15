<?php

use App\Http\Controllers\Admin\ArticleCrudController;
use App\Http\Controllers\Admin\CategoryCrudController;
use App\Http\Controllers\Admin\TagCrudController;
use Illuminate\Support\Facades\Route;

// --------------------------
// Custom Backpack Routes
// --------------------------
// This route file is loaded automatically by Backpack\CRUD.
// Routes you generate using Backpack\Generators will be placed here.

Route::group([
    'prefix'     => config('backpack.base.route_prefix', 'admin'),
    'middleware' => array_merge(
        (array) config('backpack.base.web_middleware', 'web'),
        (array) config('backpack.base.middleware_key', 'admin')
    ),
    'namespace' => 'App\Http\Controllers\Admin',
], function () { // custom admin routes
    Route::crud('article', ArticleCrudController::class);
    Route::crud('category', CategoryCrudController::class);
    Route::crud('tag', TagCrudController::class);
}); // this should be the absolute last line of this file

/**
 * DO NOT ADD ANYTHING HERE.
 */
