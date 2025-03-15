<?php

use App\Http\Controllers\Admin\ArticleCrudController;
use App\Http\Controllers\Admin\CategoryCrudController;
use App\Http\Controllers\Admin\TagCrudController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('{page}/{subs?}', ['uses' => '\App\Http\Controllers\PageController@index'])
    ->where(['page' => '^(((?=(?!admin))(?=(?!\/)).))*$', 'subs' => '.*']);

Route::group([
    'prefix'     => config('backpack.base.route_prefix', 'admin'),
    'middleware' => ['web', 'auth'],
], function () {
    //NewsCRUD
    Route::crud('article', ArticleCrudController::class);
    Route::crud('category', CategoryCrudController::class);
    Route::crud('tag', TagCrudController::class);
});
