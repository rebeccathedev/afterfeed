<?php

namespace App\Services;

use App\Models\AppSetting;

class AppSettings
{
    private ?array $values = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $this->values ??= AppSetting::all()
            ->mapWithKeys(fn (AppSetting $setting): array => [$setting->key => $setting->value])
            ->all();

        return $this->values[$key] ?? $default;
    }

    public function set(array $values): void
    {
        foreach ($values as $key => $value) {
            AppSetting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
        $this->values = null;
    }

    public function all(): array
    {
        $mappings = $this->get('privacy_name_mappings');
        if ($mappings === null) {
            $replacement = (string) $this->get('privacy_name_replacement', '');
            $mappings = collect((array) $this->get('privacy_deadnames', []))
                ->map(fn (string $name): array => ['from' => $name, 'to' => $replacement])->all();
        }

        return [
            'timezone' => $this->get('timezone', config('app.timezone', 'UTC')),
            'timeline_per_page' => (int) $this->get('timeline_per_page', 18),
            'default_export_strip_metadata' => (bool) $this->get('default_export_strip_metadata', true),
            'default_export_include_links' => (bool) $this->get('default_export_include_links', false),
            'privacy_hide_deadnames' => (bool) $this->get('privacy_hide_deadnames', false),
            'privacy_deadnames' => (array) $this->get('privacy_deadnames', []),
            'privacy_name_replacement' => (string) $this->get('privacy_name_replacement', ''),
            'privacy_name_mappings' => (array) $mappings,
        ];
    }
}
