<?php

namespace App\Services;

class PrivacyFilter
{
    public function __construct(private readonly AppSettings $settings) {}

    public function apply(mixed $value): mixed
    {
        if (! $this->settings->get('privacy_hide_deadnames', false)) {
            return $value;
        }
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->apply($item), $value);
        }
        if (! is_string($value)) {
            return $value;
        }

        $mappings = $this->mappings();
        usort($mappings, fn (array $left, array $right): int => mb_strlen($right['from']) <=> mb_strlen($left['from']));
        foreach ($mappings as $mapping) {
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($mapping['from'], '/').'(?![\p{L}\p{N}])/iu';
            $value = preg_replace($pattern, $mapping['to'], $value) ?? $value;
        }

        return $value;
    }

    private function mappings(): array
    {
        $mappings = $this->settings->get('privacy_name_mappings');
        if ($mappings === null) {
            $replacement = (string) $this->settings->get('privacy_name_replacement', '');
            $mappings = array_map(fn (string $name): array => ['from' => $name, 'to' => $replacement], (array) $this->settings->get('privacy_deadnames', []));
        }

        return array_values(array_filter(array_map(fn (mixed $mapping): ?array => is_array($mapping) && trim((string) ($mapping['from'] ?? '')) !== ''
            ? ['from' => trim((string) $mapping['from']), 'to' => trim((string) ($mapping['to'] ?? ''))]
            : null, (array) $mappings)));
    }
}
