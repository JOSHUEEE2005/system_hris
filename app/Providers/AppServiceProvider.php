<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

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
        $assetUrl = trim((string) config('app.asset_url', ''));
        $appUrl = trim((string) config('app.url', ''));
        $railwayDomain = trim((string) (getenv('RAILWAY_PUBLIC_DOMAIN') ?: env('RAILWAY_PUBLIC_DOMAIN', '')));

        $isHttpsDeployment = str_starts_with(strtolower($assetUrl), 'https://')
            || str_starts_with(strtolower($appUrl), 'https://')
            || $railwayDomain !== ''
            || app()->environment('production');

        if ($isHttpsDeployment) {
            URL::forceScheme('https');

            $forcedUrl = $assetUrl !== '' ? $assetUrl : $appUrl;

            if (($forcedUrl === '' || $forcedUrl === 'http://localhost') && $railwayDomain !== '') {
                $forcedUrl = 'https://'.$railwayDomain;
            }

            if ($forcedUrl !== '' && $forcedUrl !== 'http://localhost') {
                $httpsUrl = preg_replace('#^http://#i', 'https://', $forcedUrl);
                URL::forceRootUrl(rtrim($httpsUrl, '/'));
            }
        }
    }
}