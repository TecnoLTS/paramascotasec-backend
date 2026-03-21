<?php

declare(strict_types=1);

namespace App\Support;

final class ProductAudience
{
    public static function normalizeCategory(?string $value, ?string $fallbackProductType = null): string
    {
        $productType = self::normalizeProductType($fallbackProductType);
        if ($productType !== '') {
            return self::categoryForProductType($productType);
        }

        return self::normalizeCategoryToken((string)$value);
    }

    public static function normalizeProductType(?string $value, ?string $fallbackCategory = null): string
    {
        $type = self::normalizeProductTypeToken((string)$value);
        if ($type !== '') {
            return $type;
        }

        $category = self::normalizeCategoryToken((string)$fallbackCategory);
        return match ($category) {
            'comida' => 'comida',
            'ropa' => 'ropa',
            'cuidados' => 'cuidado',
            'accesorios' => 'accesorios',
            default => '',
        };
    }

    public static function categoryForProductType(?string $productType): string
    {
        return match (self::normalizeProductType($productType)) {
            'comida' => 'comida',
            'ropa' => 'ropa',
            'cuidado' => 'cuidados',
            'accesorios' => 'accesorios',
            default => '',
        };
    }

    public static function normalizeSpeciesLabel(?string $value, ?string $fallbackGender = null): string
    {
        $clean = self::clean($value);
        $token = self::tokenize($clean);

        if (in_array($token, ['dog', 'perro', 'perros', 'canino', 'caninos'], true) || str_contains($token, 'perro') || str_contains($token, 'canin')) {
            return 'Perro';
        }

        if (in_array($token, ['cat', 'gato', 'gatos', 'felino', 'felinos'], true) || str_contains($token, 'gato') || str_contains($token, 'felin')) {
            return 'Gato';
        }

        if (
            in_array($token, ['unisex', 'ambos', 'both', 'perroygato', 'gatoyperro', 'perrosygatos', 'gatosyperros'], true)
            || str_contains($token, 'amb')
            || str_contains($token, 'both')
            || str_contains($token, 'unisex')
        ) {
            return 'Perro y gato';
        }

        if ($clean !== '') {
            return $clean;
        }

        return self::speciesLabelFromGender($fallbackGender);
    }

    public static function resolveGender(?string $species, ?string $fallbackGender = null): string
    {
        $token = self::tokenize(self::normalizeSpeciesLabel($species, $fallbackGender));

        if ($token === 'perro') {
            return 'dog';
        }

        if ($token === 'gato') {
            return 'cat';
        }

        $fallback = strtolower(trim((string)$fallbackGender));
        if (in_array($fallback, ['dog', 'cat'], true)) {
            return $fallback;
        }

        return 'Unisex';
    }

    private static function speciesLabelFromGender(?string $gender): string
    {
        return match (strtolower(trim((string)$gender))) {
            'dog' => 'Perro',
            'cat' => 'Gato',
            'unisex' => 'Perro y gato',
            default => '',
        };
    }

    private static function clean(?string $value): string
    {
        return trim((string)(preg_replace('/\s+/', ' ', (string)$value) ?? $value));
    }

    private static function tokenize(string $value): string
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

        return preg_replace('/[^a-z0-9]+/i', '', strtolower($ascii)) ?? '';
    }

    private static function normalizeCategoryToken(string $value): string
    {
        $token = self::tokenize(self::clean($value));
        if ($token === '') {
            return '';
        }

        if (self::tokenContainsAny($token, ['ropa', 'vestimenta', 'vestido', 'prenda', 'abrigo', 'camiseta', 'sueter', 'sudadera'])) {
            return 'ropa';
        }

        if (self::tokenContainsAny($token, ['cuidado', 'cuidados', 'higiene', 'medicina', 'medicinas', 'salud', 'farmacia', 'antiparasit', 'pipeta', 'shampoo'])) {
            return 'cuidados';
        }

        if (self::tokenContainsAny($token, ['comida', 'alimento', 'snack', 'golosina', 'croqueta', 'pienso', 'lata'])) {
            return 'comida';
        }

        if (self::tokenContainsAny($token, ['accesorio', 'accesorios', 'juguete', 'juguetes', 'cama', 'camas', 'comedero', 'comederos', 'plato', 'platos', 'correa', 'correas', 'collar', 'collares', 'arnes', 'arneses', 'transportadora', 'transportadoras', 'bolsa', 'bolsas'])) {
            return 'accesorios';
        }

        return '';
    }

    private static function normalizeProductTypeToken(string $value): string
    {
        $token = self::tokenize(self::clean($value));
        if ($token === '') {
            return '';
        }

        if (self::tokenContainsAny($token, ['ropa', 'vestimenta', 'vestido', 'prenda', 'abrigo', 'camiseta', 'sueter', 'sudadera'])) {
            return 'ropa';
        }

        if (self::tokenContainsAny($token, ['cuidado', 'cuidados', 'higiene', 'medicina', 'medicinas', 'salud', 'farmacia', 'antiparasit', 'pipeta', 'shampoo'])) {
            return 'cuidado';
        }

        if (self::tokenContainsAny($token, ['comida', 'alimento', 'snack', 'golosina', 'croqueta', 'pienso', 'lata'])) {
            return 'comida';
        }

        if (self::tokenContainsAny($token, ['accesorio', 'accesorios', 'juguete', 'juguetes', 'cama', 'camas', 'comedero', 'comederos', 'plato', 'platos', 'correa', 'correas', 'collar', 'collares', 'arnes', 'arneses', 'transportadora', 'transportadoras', 'bolsa', 'bolsas'])) {
            return 'accesorios';
        }

        return '';
    }

    private static function tokenContainsAny(string $token, array $fragments): bool
    {
        foreach ($fragments as $fragment) {
            if ($fragment !== '' && str_contains($token, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
