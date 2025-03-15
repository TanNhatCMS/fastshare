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
        // register its dependencies
        //$this->app->register(\Cviebrock\EloquentSluggable\ServiceProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->overrideConfigValues();
    }

    /**
     * Override config values.
     */
    protected function overrideConfigValues(): void
    {
        $config = [];
        if (config('settings.show_powered_by')) {
            $config['backpack.ui.show_powered_by'] = config('settings.show_powered_by') == '1';
        }
        config($config);
    }
}
