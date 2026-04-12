<?php

declare(strict_types=1);

namespace App\Support;

final class ProductVariantMetadata
{
    private const ATTRIBUTE_LABEL_KEYS = [
        'variantLabel',
        'size',
        'weight',
        'presentation',
        'packaging',
        'dosage',
        'volume',
        'range',
        'color',
    ];

    private const NAMED_SIZE_VALUES = [
        'XXS' => 0.5,
        'XS' => 1.0,
        'S' => 2.0,
        'M' => 3.0,
        'L' => 4.0,
        'XL' => 5.0,
        'XXL' => 6.0,
        'STANDARD' => 2.5,
    ];

    public static function apply(array $product, array $attributes): array
    {
        $label = self::resolveVariantLabel($product, $attributes);
        if ($label === '') {
            unset($attributes['variantLabel'], $attributes['variantBaseName'], $attributes['variantGroupKey']);
            return $attributes;
        }

        $baseName = self::resolveVariantBaseName($product, $attributes, $label);
        $groupKey = self::buildGroupKey($product, $attributes, $baseName);

        $attributes['variantLabel'] = $label;
        $attributes['variantBaseName'] = $baseName;
        $attributes['variantGroupKey'] = $groupKey;

        if (self::isMeasurementLikeLabel($label) && trim((string)($attributes['size'] ?? '')) === '') {
            $attributes['size'] = $label;
        }

        return $attributes;
    }

    public static function resolveVariantLabel(array $product, array $attributes): string
    {
        $explicit = trim((string)($product['variantLabel'] ?? ''));
        if ($explicit !== '') {
            return self::normalizeLabel($explicit);
        }

        foreach (self::ATTRIBUTE_LABEL_KEYS as $key) {
            $value = trim((string)($attributes[$key] ?? ''));
            if ($value !== '') {
                return self::normalizeLabel($value);
            }
        }

        $name = trim((string)($product['name'] ?? ''));
        if ($name === '') {
            return '';
        }

        $patterns = [
            '/((?:\d+(?:[.,]\d+)?\s?(?:KGS?|KG|K|GR|G|LB|L|ML|MG|OZ))(?:\s*-\s*(?:\d+(?:[.,]\d+)?\s?(?:KGS?|KG|K|GR|G|LB|L|ML|MG|OZ)))?)$/iu',
            '/((?:\d+\s*x\s*\d+\s*(?:TABS?|DS|UN|UNI|PACK|PZA|PZ))|(?:x\s*\d+)|(?:\d+\s*(?:TABS?|DS|UN|UNI|PACK|PZA|PZ)))$/iu',
            '/((?:XXS|XS|S|M|L|XL|XXL|STANDARD))$/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $name, $matches) === 1) {
                return self::normalizeLabel((string)($matches[1] ?? ''));
            }
        }

        return '';
    }

    public static function resolveVariantBaseName(array $product, array $attributes, ?string $label = null): string
    {
        $explicit = trim((string)($product['variantBaseName'] ?? ''));
        if ($explicit !== '') {
            return self::cleanWhitespace($explicit);
        }

        $attributeBase = trim((string)($attributes['variantBaseName'] ?? ''));
        if ($attributeBase !== '') {
            return self::cleanWhitespace($attributeBase);
        }

        $name = self::cleanWhitespace((string)($product['name'] ?? ''));
        $label = $label ?? self::resolveVariantLabel($product, $attributes);
        if ($name === '' || $label === '') {
            return $name;
        }

        $candidates = array_values(array_unique(array_filter([
            $label,
            preg_replace('/\s+/', '', $label) ?: '',
            str_replace('-', '\\s*-\\s*', preg_quote($label, '/')),
        ])));

        $base = $name;
        foreach ($candidates as $candidate) {
            $escaped = $candidate === $label
                ? preg_quote($candidate, '/')
                : $candidate;
            $separator = self::requiresSeparatedSuffix($candidate)
                ? '(?:\s+|-)'
                : '(?:\s+|-)?';
            $base = preg_replace('/' . $separator . $escaped . '$/iu', '', $base) ?? $base;
            $base = self::cleanWhitespace($base);
        }

        return $base !== '' ? $base : $name;
    }

    public static function describeLabel(string $label): array
    {
        $normalized = self::normalizeLabel($label);
        if ($normalized === '') {
            return ['dimension' => 'unknown', 'value' => 0.0];
        }

        if (preg_match('/^(XXS|XS|S|M|L|XL|XXL|STANDARD)$/', $normalized, $named) === 1) {
            return [
                'dimension' => 'size',
                'value' => self::NAMED_SIZE_VALUES[$named[1]] ?? 0.0,
            ];
        }

        if (preg_match('/^(\d+(?:\.\d+)?)(?:KG|KGS?|K)-(\d+(?:\.\d+)?)(?:KG|KGS?|K)$/', $normalized, $rangeKg) === 1) {
            $midpoint = ((float)$rangeKg[1] + (float)$rangeKg[2]) / 2;
            return ['dimension' => 'weight', 'value' => $midpoint * 1000];
        }

        if (preg_match('/^(\d+(?:\.\d+)?)(?:GR|G|MG|ML|L|LB|OZ|KG|KGS?|K)$/', $normalized, $amount) === 1) {
            preg_match('/(KG|KGS?|K|GR|G|MG|ML|L|LB|OZ)$/', $normalized, $unitMatch);
            $unit = $unitMatch[1] ?? '';
            $value = (float)$amount[1];
            return self::convertUnitToDescriptor($value, $unit);
        }

        if (preg_match('/^(?:X)?(\d+(?:\.\d+)?)(?:X1)?(TABS?|DS|UN|UNI|PACK|PZA|PZ)$/', $normalized, $count) === 1) {
            return ['dimension' => 'count', 'value' => (float)$count[1]];
        }

        return ['dimension' => 'unknown', 'value' => 0.0];
    }

    public static function normalizeLabel(string $value): string
    {
        $normalized = strtoupper(trim($value));
        if ($normalized === '') {
            return '';
        }

        $normalized = str_replace(',', '.', $normalized);
        $normalized = preg_replace('/\s*-\s*/', '-', $normalized) ?? $normalized;
        $normalized = preg_replace('/(\d)\s+(KGS?|KG|K|GR|G|LB|L|ML|MG|OZ|TABS?|DS|UN|UNI|PACK|PZA|PZ)\b/u', '$1$2', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private static function buildGroupKey(array $product, array $attributes, string $baseName): string
    {
        $parts = array_filter([
            trim((string)($product['brand'] ?? '')),
            trim((string)($product['category'] ?? '')),
            trim((string)($product['gender'] ?? '')),
            trim($baseName),
            trim((string)($attributes['target'] ?? '')),
            trim((string)($attributes['flavor'] ?? '')),
            trim((string)($attributes['line'] ?? '')),
            trim((string)($attributes['species'] ?? '')),
        ], static fn ($value) => $value !== '');

        return self::slugify(implode('|', $parts));
    }

    private static function slugify(string $value): string
    {
        $ascii = strtr($value, [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
            'Á' => 'a', 'À' => 'a', 'Ä' => 'a', 'Â' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'É' => 'e', 'È' => 'e', 'Ë' => 'e', 'Ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'Í' => 'i', 'Ì' => 'i', 'Ï' => 'i', 'Î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'Ó' => 'o', 'Ò' => 'o', 'Ö' => 'o', 'Ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'Ú' => 'u', 'Ù' => 'u', 'Ü' => 'u', 'Û' => 'u',
            'ñ' => 'n', 'Ñ' => 'n',
        ]);
        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($ascii)) ?? '';
        return trim($slug, '-') ?: 'group';
    }

    private static function convertUnitToDescriptor(float $value, string $unit): array
    {
        return match ($unit) {
            'KG', 'KGS', 'K' => ['dimension' => 'weight', 'value' => $value * 1000],
            'LB' => ['dimension' => 'weight', 'value' => $value * 453.592],
            'L' => ['dimension' => 'volume', 'value' => $value * 1000],
            'GR', 'G' => ['dimension' => 'weight', 'value' => $value],
            'MG' => ['dimension' => 'count', 'value' => $value],
            'ML' => ['dimension' => 'volume', 'value' => $value],
            'OZ' => ['dimension' => 'weight', 'value' => $value * 28.3495],
            default => ['dimension' => 'unknown', 'value' => $value],
        };
    }

    private static function requiresSeparatedSuffix(string $label): bool
    {
        return preg_match('/^(XXS|XS|S|M|L|XL|XXL|STANDARD)$/', self::normalizeLabel($label)) === 1;
    }

    private static function isMeasurementLikeLabel(string $label): bool
    {
        $dimension = self::describeLabel($label)['dimension'] ?? 'unknown';
        return in_array($dimension, ['weight', 'volume'], true);
    }

    private static function cleanWhitespace(string $value): string
    {
        return trim((string)(preg_replace('/\s+/', ' ', $value) ?? $value));
    }
}
