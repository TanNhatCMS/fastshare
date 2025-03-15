<?php

use Illuminate\Support\Facades\Route;

Route::get('/', static function () {
    return view('welcome');
});
//
//Route::get('{page}/{subs?}', ['uses' => '\App\Http\Controllers\PageController@index'])
//    ->where(['page' => '^(((?=(?!admin))(?=(?!\/)).))*$', 'subs' => '.*']);
