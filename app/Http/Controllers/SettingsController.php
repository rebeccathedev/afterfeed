<?php

namespace App\Http\Controllers;

use App\Services\AppSettings;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(AppSettings $settings): View
    {
        $values = $settings->all();
        $timezones = DateTimeZone::listIdentifiers();
        $diagnostics = [
            'database' => DB::connection()->getDriverName(),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'remote_mcp' => request()->user()->tokens()->exists(),
            'mcp_endpoint' => url('/api/mcp'),
            'allowed_origins' => config('services.afterfeed_mcp.allowed_origins'),
        ];

        return view('settings.edit', compact('values', 'timezones', 'diagnostics'));
    }

    public function update(Request $request, AppSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'timezone' => ['required', Rule::in(DateTimeZone::listIdentifiers())],
            'timeline_per_page' => ['required', Rule::in([12, 18, 24, 36, 48])],
            'default_export_strip_metadata' => ['nullable', 'boolean'],
            'default_export_include_links' => ['nullable', 'boolean'],
            'privacy_hide_deadnames' => ['nullable', 'boolean'],
            'privacy_name_mappings' => ['nullable', 'string', 'max:10000'],
        ]);
        $settings->set([
            'timezone' => $data['timezone'],
            'timeline_per_page' => (int) $data['timeline_per_page'],
            'default_export_strip_metadata' => $request->boolean('default_export_strip_metadata'),
            'default_export_include_links' => $request->boolean('default_export_include_links'),
            'privacy_hide_deadnames' => $request->boolean('privacy_hide_deadnames'),
            'privacy_name_mappings' => $this->nameMappings($data['privacy_name_mappings'] ?? ''),
        ]);

        return back()->with('status', 'Settings saved.');
    }

    private function nameMappings(string $input): array
    {
        return collect(preg_split('/\R/u', $input))
            ->map(function (string $line): ?array {
                [$from, $to] = array_pad(preg_split('/\s*(?:=>|→)\s*/u', trim($line), 2), 2, '');

                return trim($from) === '' ? null : ['from' => trim($from), 'to' => trim($to)];
            })->filter()->unique('from')->take(50)->values()->all();
    }
}
