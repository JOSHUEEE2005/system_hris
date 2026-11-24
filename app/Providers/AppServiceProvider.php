<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (app()->environment('production')) {
            URL::forceScheme('https');

            $appUrl = trim((string) config('app.url'));
            if ($appUrl !== '' && $appUrl !== 'http://localhost') {
                URL::forceRootUrl(preg_replace('#^http://#i', 'https://', $appUrl));
            }
        }
    }
}
