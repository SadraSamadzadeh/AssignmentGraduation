<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Allow API documentation access in all environments
        Gate::define('viewApiDocs', function () {
            return true; // Change this to restrict access in production
        });
    }
}