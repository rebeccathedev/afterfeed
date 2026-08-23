<?php

namespace App\Http\Middleware;

use App\Services\AppSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class ApplyAppSettings
{
    public function __construct(private readonly AppSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (Schema::hasTable('app_settings')) {
            $timezone = $this->settings->get('timezone', config('app.timezone', 'UTC'));
            config(['app.timezone' => $timezone]);
            date_default_timezone_set($timezone);
        }

        return $next($request);
    }
}
