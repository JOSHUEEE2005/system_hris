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
        $assetUrl = trim((string) env('ASSET_URL', ''));
        $appUrl = trim((string) env('APP_URL', ''));
        $isRailway = trim((string) getenv('RAILWAY_PUBLIC_DOMAIN')) !== '';
        $isHttpsDeployment = str_starts_with(strtolower($assetUrl), 'https://')
            || str_starts_with(strtolower($appUrl), 'https://')
            || $isRailway
            || app()->environment('production');

        if ($isHttpsDeployment) {
            URL::forceScheme('https');

            $forcedUrl = $assetUrl !== '' ? $assetUrl : $appUrl;
            if ($forcedUrl === '' && $isRailway) {
                $forcedUrl = 'https://'.trim((string) getenv('RAILWAY_PUBLIC_DOMAIN'));
            }
            if ($forcedUrl !== '' && $forcedUrl !== 'http://localhost') {
                URL::forceRootUrl(preg_replace('#^http://#i', 'https://', $forcedUrl));
            }
        }
    }
}
