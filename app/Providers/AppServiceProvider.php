<?php

namespace App\Providers;

use App\Http\Controllers\ElfinderCustomController;
use Barryvdh\Elfinder\ElfinderController;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ElfinderController::class, ElfinderCustomController::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
