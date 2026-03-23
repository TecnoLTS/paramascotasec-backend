<?php

declare(strict_types=1);

namespace App\Support;

final class CatalogProductTextNormalizer
{
    private const TARGET_LABELS = [
        'adultos' => 'para adultos',
        'adultos-rmg' => 'para perros adultos de razas medianas y grandes',
        'adultos-rpm' => 'para perros adultos de razas pequenas y medianas',
        'adultos-rmp' => 'para perros adultos de razas medianas y pequenas',
        'cachorros' => 'para cachorros',
        'cachorros-rmg' => 'para cachorros de razas medianas y grandes',
        'cachorros-rpm' => 'para cachorros de razas pequenas y medianas',
        'gatitos' => 'para gatitos',
        'weight-control' => 'para control de peso',
        'adultos-light' => 'para perros adultos formula light',
        'adultos-senior' => 'para perros adultos senior',
        'esterilizados' => 'para gatos esterilizados',
        'esterilizados-weight-control' => 'para gatos esterilizados con control de peso',
        'urinary' => 'para gatos con formula urinary',
        'adultos-y-gatitos' => 'para gatos adultos y gatitos',
        'humedo' => '',
        'snacks' => '',
        'snacks-liquidos' => '',
        'snacks-dental' => '',
    ];

    private const SPECIAL_NAMES = [
        'VM150062' => 'Nobivac DHPPi 10x1ds',
        'VM150060' => 'Nobivac DAPPv+L4 25x1ds',
        'VM150061' => 'Nobivac DAPPvL2 25x1ds',
        'VM150075' => 'Nobivac DAPPvL2+CV 25x1ds',
        'VM150063' => 'Nobivac KC 5x1ds+5x1ds dil',
        'VM150064' => 'Nobivac Intratrac Oral BB 25x1ds',
        'VM150065' => 'Nobivac Parvo-C 10x1ds',
        'SYN-NOBIVAC-FELINE-1-HCP-25X1DS' => 'Nobivac Feline 1-HCP 25x1ds',
        'VM150072' => 'Nobivac Rabies 10x10ds',
        'VM150066' => 'Nobivac Puppy DP 10x1ds',
        'VM150068' => 'Nobivac Rabies 10x1ds',
        'VM150056' => 'Bravecto 250mg 1x1tab',
        'VM150057' => 'Bravecto 500mg 1x1tab',
        'VM150058' => 'Bravecto 1000mg 1x1tab',
        'VM150059' => 'Bravecto 1400mg 1x1tab',
        'VM150055' => 'Bravecto 112.5mg 1x1tab',
        'VM150071' => 'Bravecto Plus Cat 500mg 1x1ds',
        'VM150070' => 'Bravecto Plus Cat 250mg 1x1ds',
        'VM150069' => 'Bravecto Plus Cat 112.5mg 1x1ds',
        'SYN-BRAVECTO-1M-45MG-1X1TAB' => 'Bravecto 1M 45mg 1x1tab',
        'SYN-BRAVECTO-1M-100MG-1X1TAB' => 'Bravecto 1M 100mg 1x1tab',
        'SYN-BRAVECTO-1M-200MG-1X1TAB' => 'Bravecto 1M 200mg 1x1tab',
        'SYN-BRAVECTO-1M-400MG-1X1TAB' => 'Bravecto 1M 400mg 1x1tab',
    ];

    private const AVANT_NAME_MAP = [
        'AVANT Ad Min Peq' => ['AVANT Adultos Miniaturas y Pequenos', 'para perros adultos de razas miniaturas y pequenas', null],
        'AVANT Ad Med Gra' => ['AVANT Adultos Medianos y Grandes', 'para perros adultos de razas medianas y grandes', null],
        'AVANT Ca Min Peq' => ['AVANT Cachorros Miniaturas y Pequenos', 'para cachorros de razas miniaturas y pequenas', null],
        'AVANT Ca Med Gra' => ['AVANT Cachorros Medianos y Grandes', 'para cachorros de razas medianas y grandes', null],
        'AVANT Control De Peso' => ['AVANT Control de Peso', 'para perros adultos con control de peso', null],
        'AVANT Perros Senior' => ['AVANT Perros Senior', 'para perros senior', null],
        'AVANT Gatos Vet Urinary' => ['AVANT Gatos Veterinary Urinary', 'para gatos con formula urinary', null],
        'AVANT Gatitos' => ['AVANT Gatitos', 'para gatitos', null],
        'AVANT Gatos' => ['AVANT Gatos', 'para gatos adultos', null],
        'AVANT Ca Pavvegqui' => ['AVANT Cachorros Pavo y Vegetales con Quinoa', 'para cachorros', 'pavo y vegetales con quinoa'],
        'AVANT Ca Pollvegarr' => ['AVANT Cachorros Pollo y Vegetales con Arroz', 'para cachorros', 'pollo y vegetales con arroz'],
        'AVANT Ad Resvegarr' => ['AVANT Adultos Res y Vegetales con Arroz', 'para perros adultos', 'res y vegetales con arroz'],
        'AVANT Ad Pavvegarr' => ['AVANT Adultos Pavo y Vegetales con Arroz', 'para perros adultos', 'pavo y vegetales con arroz'],
        'AVANT Ad Cervegarr' => ['AVANT Adultos Cerdo y Vegetales con Arroz', 'para perros adultos', 'cerdo y vegetales con arroz'],
        'AVANT Ad Pollvegqui' => ['AVANT Adultos Pollo y Vegetales con Quinoa', 'para perros adultos', 'pollo y vegetales con quinoa'],
        'AVANT Ad Resvegqui' => ['AVANT Adultos Res y Vegetales con Quinoa', 'para perros adultos', 'res y vegetales con quinoa'],
        'AVANT Ad Cervegqui' => ['AVANT Adultos Cerdo y Vegetales con Quinoa', 'para perros adultos', 'cerdo y vegetales con quinoa'],
        'AVANT Cocido' => ['AVANT Cocido', 'para perros', null],
        'AVANT Barf' => ['AVANT BARF', 'para perros', null],
        'AVANT Diet Bla Li Pollveg' => ['AVANT Dieta Blanda Liofilizada Pollo y Vegetales', 'para perros', 'pollo y vegetales'],
        'AVANT Diet Bla Li Poll' => ['AVANT Dieta Blanda Liofilizada Pollo', 'para perros', 'pollo'],
        'Galletas AVANT Cachorros Dha' => ['Galletas AVANT Cachorros DHA', 'para cachorros', null],
        'Galletas AVANT Adultos Salud Ar' => ['Galletas AVANT Adultos Salud AR', 'para perros adultos', null],
        'Galletas AVANT Adultos Salud In' => ['Galletas AVANT Adultos Salud IN', 'para perros adultos', null],
    ];

    private const PROCAN_NAME_MAP = [
        'PRO-CAN Crp Pollcerleche' => ['PRO-CAN Cachorros Razas Pequenas Pollo, Cereales y Leche', 'para cachorros de razas pequenas', 'pollo, cereales y leche'],
        'PRO-CAN Arp Pollcarveg' => ['PRO-CAN Adultos Razas Pequenas Pollo, Carne y Vegetales', 'para perros adultos de razas pequenas', 'pollo, carne y vegetales'],
        'PRO-CAN Crmg Original' => ['PRO-CAN Cachorros Razas Medianas y Grandes Receta Original', 'para cachorros de razas medianas y grandes', 'receta original'],
        'PRO-CAN Armg Original' => ['PRO-CAN Adultos Razas Medianas y Grandes Receta Original', 'para perros adultos de razas medianas y grandes', 'receta original'],
        'PRO-CAN Senior' => ['PRO-CAN Senior', 'para perros senior', null],
        'PRO-CAN Equilibrio Natural Cachorros' => ['PRO-CAN Equilibrio Natural Cachorros', 'para cachorros', null],
        'PRO-CAN Equilibrio Natural' => ['PRO-CAN Equilibrio Natural', 'para perros adultos', null],
        'PRO-CAN Armg Pollarrvegn' => ['PRO-CAN Adultos Razas Medianas y Grandes Pollo, Arroz y Vegetales', 'para perros adultos de razas medianas y grandes', 'pollo, arroz y vegetales'],
        'PRO-CAN Armg Cararrvegv' => ['PRO-CAN Adultos Razas Medianas y Grandes Carne, Arroz y Vegetales', 'para perros adultos de razas medianas y grandes', 'carne, arroz y vegetales'],
        'PRO-CAN Trocitos Cerdo Cachorros' => ['PRO-CAN Trocitos Cerdo Cachorros', 'para cachorros', 'cerdo'],
        'PRO-CAN Trocitos Pollo Cachorros' => ['PRO-CAN Trocitos Pollo Cachorros', 'para cachorros', 'pollo'],
        'PRO-CAN Trocitos Cerdo Adultos' => ['PRO-CAN Trocitos Cerdo Adultos', 'para perros adultos', 'cerdo'],
        'PRO-CAN Trocitos Pollo Adultos' => ['PRO-CAN Trocitos Pollo Adultos', 'para perros adultos', 'pollo'],
        'PRO-CAN Trocitos Pavo Adultos' => ['PRO-CAN Trocitos Pavo Adultos', 'para perros adultos', 'pavo'],
        'PRO-CAN Trocitos Res Adultos' => ['PRO-CAN Trocitos Res Adultos', 'para perros adultos', 'res'],
        'PROCAN Trocitos Ca Poll' => ['PRO-CAN Trocitos Cachorros Pollo', 'para cachorros', 'pollo'],
        'PROCAN Trocitos Ca Cer' => ['PRO-CAN Trocitos Cachorros Cerdo', 'para cachorros', 'cerdo'],
        'PROCAN Trocitos Ad Poll' => ['PRO-CAN Trocitos Adultos Pollo', 'para perros adultos', 'pollo'],
        'PROCAN Trocitos Ad Cer' => ['PRO-CAN Trocitos Adultos Cerdo', 'para perros adultos', 'cerdo'],
        'PROCAN Trocitos Ad Pav' => ['PRO-CAN Trocitos Adultos Pavo', 'para perros adultos', 'pavo'],
        'PROCAN Trocitos Ad Res' => ['PRO-CAN Trocitos Adultos Res', 'para perros adultos', 'res'],
        'Galletas PROCAN Crmg Leche' => ['Galletas PRO-CAN Cachorros Razas Medianas y Grandes Leche', 'para cachorros de razas medianas y grandes', null],
        'Galletas PROCAN Sal Dent' => ['Galletas PRO-CAN Salud Dental', 'para perros adultos', null],
        'Galleta PROCAN Piel Y Pel' => ['Galletas PRO-CAN Piel y Pelaje', 'para perros adultos', null],
        'Galletas PROCAN Piel Y Pel' => ['Galletas PRO-CAN Piel y Pelaje', 'para perros adultos', null],
        'PROCAN 6 Palitosp&B Cuad Pollo' => ['PROCAN 6 Palitos P&B Cuadrados Pollo', 'para perros', 'pollo'],
    ];

    private const PROCAT_NAME_MAP = [
        'PRO-CAT Care Ad Pollo' => ['PRO-CAT Care Adultos Pollo', 'para gatos adultos', 'pollo'],
        'PRO-CAT Care Ad Mar' => ['PRO-CAT Care Adultos Mar', 'para gatos adultos', 'mar'],
        'PRO-CAT Care Control Peso' => ['PRO-CAT Care Control de Peso', 'para gatos con control de peso', null],
        'PRO-CAT Care Senior' => ['PRO-CAT Care Senior', 'para gatos senior', null],
        'PRO-CAT Care Gatitos' => ['PRO-CAT Care Gatitos', 'para gatitos', null],
        'PRO-CAT Delicias Pollo' => ['PRO-CAT Delicias Pollo', 'para gatos', 'pollo'],
        'PRO-CAT Delicias Pavo' => ['PRO-CAT Delicias Pavo', 'para gatos', 'pavo'],
        'PRO-CAT Delicias Higado' => ['PRO-CAT Delicias Higado', 'para gatos', 'higado'],
        'PRO-CAT Care Arena Sanitaria' => ['PRO-CAT Care Arena Sanitaria', 'para gatos', null],
    ];

    private const SPECIAL_DESCRIPTIONS = [
        'VM150062' => 'Vacuna viva atenuada para la prevencion de Parvovirus canino, Distemper canino, Adenovirus canino I y II y Parainfluenza. Presentacion pack 10 x 1 dosis.',
        'VM150060' => 'Vacuna multiple canina con Parvovirus, Distemper, Adenovirus tipo 2, Parainfluenza y Leptospira serovares Canicola, Icterohaemorrhagiae, Gripotyphosa y Pomona. Presentacion pack 25 x 1 dosis.',
        'VM150061' => 'Vacuna multiple canina con Parvovirus, Distemper, Adenovirus tipo 2, Parainfluenza y Leptospira serovares Canicola e Icterohaemorrhagiae. Presentacion pack 25 x 1 dosis.',
        'VM150075' => 'Vacuna multiple canina Nobivac DAPPvL2+CV. Presentacion pack 25 x 1 dosis.',
        'VM150063' => 'Vacuna intranasal para la prevencion de traqueobronquitis infecciosa canina ocasionada por Bordetella bronchiseptica y Parainfluenza canina. Presentacion pack 10 dosis.',
        'VM150064' => 'Bacterina oral viva atenuada para la prevencion de traqueobronquitis infecciosa canina ocasionada por Bordetella bronchiseptica. Presentacion pack 25 x 1 dosis.',
        'VM150065' => 'Vacuna viva atenuada de Parvovirus canino cepa 154 clonada. Presentacion pack 10 x 1 dosis.',
        'SYN-NOBIVAC-FELINE-1-HCP-25X1DS' => 'Vacuna felina atenuada para la prevencion de rinotraqueitis, calcivirus y panleucopenia. Presentacion pack 25 x 1 dosis.',
        'VM150072' => 'Vacuna antirrabica veterinaria Nobivac Rabies. Presentacion pack 10 x 10 dosis.',
        'VM150066' => 'Vacuna viva atenuada con Parvovirus canino y Distemper canino de alta masa antigenica. Presentacion pack 10 x 1 dosis.',
        'VM150068' => 'Vacuna antirrabica veterinaria de alta potencia. Presentacion pack 10 x 1 dosis.',
        'VM150056' => 'Antiparasitario canino de uso trimestral para perros de mas de 4.5 kg hasta 10 kg. Presentacion 1 tableta.',
        'VM150057' => 'Antiparasitario canino de uso trimestral para perros de mas de 10 kg hasta 20 kg. Presentacion 1 tableta.',
        'VM150058' => 'Antiparasitario canino de uso trimestral para perros de mas de 20 kg hasta 40 kg. Presentacion 1 tableta.',
        'VM150059' => 'Antiparasitario canino de uso trimestral para perros de mas de 40 kg hasta 56 kg. Presentacion 1 tableta.',
        'VM150055' => 'Antiparasitario canino de uso trimestral para perros de 2 kg hasta 4.5 kg. Presentacion 1 tableta.',
        'VM150071' => 'Antiparasitario felino Bravecto Plus Cat para gatos. Presentacion 1 dosis.',
        'VM150070' => 'Antiparasitario felino Bravecto Plus Cat para gatos. Presentacion 1 dosis.',
        'VM150069' => 'Antiparasitario felino Bravecto Plus Cat para gatos. Presentacion 1 dosis.',
        'SYN-BRAVECTO-1M-45MG-1X1TAB' => 'Antiparasitario canino Bravecto 1M. Presentacion 1 tableta de 45 mg.',
        'SYN-BRAVECTO-1M-100MG-1X1TAB' => 'Antiparasitario canino Bravecto 1M. Presentacion 1 tableta de 100 mg.',
        'SYN-BRAVECTO-1M-200MG-1X1TAB' => 'Antiparasitario canino Bravecto 1M. Presentacion 1 tableta de 200 mg.',
        'SYN-BRAVECTO-1M-400MG-1X1TAB' => 'Antiparasitario canino Bravecto 1M. Presentacion 1 tableta de 400 mg.',
    ];

    public static function normalizeItem(array $item): array
    {
        $attributes = self::normalizeAttributes($item['attributes'] ?? []);
        $legacyId = trim((string)($item['legacyId'] ?? $item['legacy_id'] ?? ''));
        $brand = trim((string)($item['brand'] ?? ''));
        $name = trim((string)($item['name'] ?? ''));
        $category = trim((string)($item['category'] ?? ''));
        $gender = trim((string)($item['gender'] ?? ''));
        $productType = trim((string)($item['productType'] ?? $item['product_type'] ?? ''));

        $resolved = self::normalizeNameContext($legacyId, $brand, $name, $attributes);

        $item['name'] = $resolved['name'];
        $item['description'] = self::buildDescription(
            $legacyId,
            $brand,
            $resolved['name'],
            $category,
            $gender,
            $productType,
            $attributes,
            $resolved['audience'],
            $resolved['recipe']
        );

        return $item;
    }

    private static function normalizeNameContext(string $legacyId, string $brand, string $name, array $attributes): array
    {
        if (isset(self::SPECIAL_NAMES[$legacyId])) {
            return [
                'name' => self::SPECIAL_NAMES[$legacyId],
                'audience' => null,
                'recipe' => self::inferRecipe(self::SPECIAL_NAMES[$legacyId], $attributes),
            ];
        }

        $mappings = match ($brand) {
            'AVANT' => self::AVANT_NAME_MAP,
            'PRO-CAN' => self::PROCAN_NAME_MAP,
            'PRO-CAT' => self::PROCAT_NAME_MAP,
            default => [],
        };

        foreach ($mappings as $needle => [$replacement, $audience, $recipe]) {
            if (str_starts_with($name, $needle)) {
                $resolvedName = preg_replace('/^' . preg_quote($needle, '/') . '/', $replacement, $name, 1) ?? $replacement;
                return [
                    'name' => self::cleanupName($resolvedName),
                    'audience' => $audience,
                    'recipe' => $recipe,
                ];
            }
        }

        $normalizedName = self::cleanupName($name);

        return [
            'name' => $normalizedName,
            'audience' => null,
            'recipe' => self::inferRecipe($normalizedName, $attributes),
        ];
    }

    private static function buildDescription(
        string $legacyId,
        string $brand,
        string $name,
        string $category,
        string $gender,
        string $productType,
        array $attributes,
        ?string $audience,
        ?string $recipe
    ): string {
        if (isset(self::SPECIAL_DESCRIPTIONS[$legacyId])) {
            return self::SPECIAL_DESCRIPTIONS[$legacyId];
        }

        $kind = self::detectKind($brand, $name, $category, $productType, $attributes);
        $presentation = self::buildPresentationText($attributes);
        $rangeText = self::buildRangeText($attributes);
        $audienceText = $audience ?: self::inferAudience($name, $attributes, $brand, $gender);
        $recipeText = $recipe ?: self::inferRecipe($name, $attributes);

        return match ($kind) {
            'dry-food' => self::joinSentences([
                self::sentenceWithRecipe('Alimento seco balanceado', $audienceText, $recipeText),
                $presentation,
            ]),
            'wet-food' => self::joinSentences([
                self::sentenceWithRecipe('Alimento humedo', $audienceText ?: self::inferAudienceFromSpecies($gender), $recipeText),
                $presentation,
            ]),
            'snack' => self::joinSentences([
                self::sentenceWithRecipe('Snack', $audienceText ?: self::inferAudienceFromSpecies($gender), $recipeText),
                self::buildSnackExtra($name, $attributes),
                $presentation,
            ]),
            'frozen-food' => self::joinSentences([
                self::sentenceWithRecipe('Alimento congelado', $audienceText ?: self::inferAudienceFromSpecies($gender), $recipeText),
                $presentation,
            ]),
            'soft-diet' => self::joinSentences([
                self::sentenceWithRecipe('Dieta blanda liofilizada', $audienceText ?: self::inferAudienceFromSpecies($gender), $recipeText),
                $presentation,
            ]),
            'supplement' => self::joinSentences([
                self::sentenceWithRecipe('Suplemento para mascotas', $audienceText, $recipeText),
                $presentation,
            ]),
            'litter' => self::joinSentences([
                'Arena sanitaria para gatos.',
                $presentation,
            ]),
            'shampoo' => self::joinSentences([
                self::buildShampooSentence($name),
                $presentation,
            ]),
            'grooming-tool' => self::joinSentences([
                self::buildGroomingSentence($name, $gender),
                $presentation ?: self::buildSizeText($attributes),
            ]),
            'hygiene' => self::joinSentences([
                self::buildHygieneSentence($brand, $name, $gender),
                $presentation ?: self::buildSizeText($attributes),
            ]),
            'antiparasitic' => self::joinSentences([
                self::buildAntiparasiticSentence($brand, $name, $gender),
                $rangeText,
                $presentation ?: self::buildSizeText($attributes),
            ]),
            'vaccine' => self::joinSentences([
                self::buildVaccineSentence($brand, $name, $gender),
                $presentation ?: self::buildSizeText($attributes),
            ]),
            'therapeutic' => self::joinSentences([
                self::buildTherapeuticSentence($brand, $name),
                $presentation ?: self::buildSizeText($attributes),
            ]),
            'home-care' => self::joinSentences([
                self::buildHomeCareSentence($brand, $name),
                $presentation ?: self::buildSizeText($attributes),
            ]),
            default => self::joinSentences([
                self::buildGenericSentence($brand, $name, $category, $gender),
                $presentation ?: self::buildSizeText($attributes),
            ]),
        };
    }

    private static function detectKind(string $brand, string $name, string $category, string $productType, array $attributes): string
    {
        $full = strtolower(trim($brand . ' ' . $name));
        $target = strtolower(trim((string)($attributes['target'] ?? '')));
        $size = strtolower(trim((string)($attributes['size'] ?? '')));

        if (in_array($brand, ['NOBIVAC', 'RECOMBITEK', 'RABISIN'], true) || str_contains($full, 'vacuna')) {
            return 'vaccine';
        }
        if (in_array($brand, ['BRAVECTO', 'FRONTLINE', 'NEXGARD'], true)) {
            return 'antiparasitic';
        }
        if (in_array($brand, ['PREVICOX', 'VETMEDIN'], true)) {
            return 'therapeutic';
        }
        if (in_array($brand, ['KLERAT', 'ZAP', 'DRAGON'], true)) {
            return 'home-care';
        }
        if ($brand === 'FURMINATOR') {
            return 'grooming-tool';
        }
        if (str_contains($full, 'arena')) {
            return 'litter';
        }
        if (str_contains($full, 'shampoo')) {
            return 'shampoo';
        }
        if ($brand === 'PET CARE' || str_contains($full, 'clorhexidina') || str_contains($full, 'miconazol') || str_contains($full, 'bano') || str_contains($full, 'colonia') || str_contains($full, 'fibropinil')) {
            return 'hygiene';
        }
        if ($brand === 'WELLNESS' && (str_contains($full, 'multi vitamin') || str_contains($full, 'skin and coat') || str_contains($full, 'artro flex'))) {
            return 'supplement';
        }
        if (str_contains($full, 'dieta blanda liofilizada')) {
            return 'soft-diet';
        }
        if ($brand === 'AVANT' && preg_match('/^(85g|180g|415g)$/', $size) && str_contains($full, 'vegetales')) {
            return 'wet-food';
        }
        if ($target === 'humedo' || preg_match('/\b(latas|pate|trocitos|delicias|cat chup|pouch|lata)\b/i', $full)) {
            return 'wet-food';
        }
        if (str_contains($full, 'barf') || str_contains($full, 'cocido')) {
            return 'frozen-food';
        }
        if (str_starts_with($target, 'snacks') || preg_match('/\b(galletas|palitos|banditas|bocaditos|minihuesos|huesos|rejos|dreambones|smartbones|delibites|cat chup)\b/i', $full)) {
            return 'snack';
        }
        if ($category === 'Alimento para perros' || $category === 'Alimento para gatos' || $productType === 'Alimento' || preg_match('/\b(kg|lb)\b/i', $size)) {
            return 'dry-food';
        }

        return 'generic';
    }

    private static function inferAudience(string $name, array $attributes, string $brand, string $gender): ?string
    {
        $target = strtolower(trim((string)($attributes['target'] ?? '')));
        if ($target !== '' && isset(self::TARGET_LABELS[$target])) {
            $resolved = self::TARGET_LABELS[$target];
            if ($resolved === 'para adultos') {
                return self::inferAudienceFromSpecies($gender, true);
            }
            if ($resolved === '') {
                return self::inferAudienceFromSpecies($gender);
            }
            return $resolved;
        }

        $lower = strtolower($name);
        $patterns = [
            'miniaturas y pequenos' => 'para perros adultos de razas miniaturas y pequenas',
            'medianos y grandes' => str_contains($lower, 'cachorros') ? 'para cachorros de razas medianas y grandes' : 'para perros adultos de razas medianas y grandes',
            'razas pequenas y medianas' => str_contains($lower, 'cachorros') ? 'para cachorros de razas pequenas y medianas' : 'para perros adultos de razas pequenas y medianas',
            'razas pequenas y miniaturas' => str_contains($lower, 'cachorros') ? 'para cachorros de razas pequenas y miniaturas' : 'para perros adultos de razas pequenas y miniaturas',
            'gatitos' => 'para gatitos',
            'gatos' => 'para gatos adultos',
            'cachorros' => 'para cachorros',
            'senior' => $gender === 'cat' ? 'para gatos senior' : 'para perros senior',
            'weight control' => $gender === 'cat' ? 'para gatos con control de peso' : 'para perros con control de peso',
            'control de peso' => $gender === 'cat' ? 'para gatos con control de peso' : 'para perros con control de peso',
            'urinary' => 'para gatos con formula urinary',
            'esterilizados' => 'para gatos esterilizados',
            'adultos' => self::inferAudienceFromSpecies($gender, true),
        ];

        foreach ($patterns as $needle => $label) {
            if (str_contains($lower, $needle)) {
                return $label;
            }
        }

        return self::inferAudienceFromSpecies($gender);
    }

    private static function inferAudienceFromSpecies(string $gender, bool $adults = false): string
    {
        return match ($gender) {
            'cat' => $adults ? 'para gatos adultos' : 'para gatos',
            'dog' => $adults ? 'para perros adultos' : 'para perros',
            default => 'para mascotas',
        };
    }

    private static function inferRecipe(string $name, array $attributes): ?string
    {
        $flavor = trim((string)($attributes['flavor'] ?? ''));
        if ($flavor !== '') {
            return $flavor;
        }

        $known = [
            'pollo y vegetales con quinoa',
            'pollo y vegetales con arroz',
            'pavo y vegetales con quinoa',
            'pavo y vegetales con arroz',
            'res y vegetales con quinoa',
            'res y vegetales con arroz',
            'cerdo y vegetales con quinoa',
            'cerdo y vegetales con arroz',
            'pollo, cereales y leche',
            'pollo, carne y vegetales',
            'pollo, arroz y vegetales',
            'carne, arroz y vegetales',
            'receta original',
            'pollo',
            'pavo',
            'carne',
            'res',
            'cerdo',
            'cordero',
            'higado',
            'pescado',
            'mar',
            'festival marino',
            'delicias del mar',
            'pollo y atun',
            'pollo y arroz',
            'carne y vegetales',
            'pollo y vegetales',
            'cordero y vegetales',
            'polllo y higado',
            'atun y tilapia',
            'atun y sardina',
            'atun, salmon y prebioticos',
            'atun, cangrejo y prebioticos',
            'pollo y arandanos',
            'pollo y prebioticos',
            'sabor a pollo',
            'sabor a carne',
            'sabor mantequilla mani',
            'sabores del mar',
            'aroma a catnip',
        ];

        $lower = strtolower($name);
        foreach ($known as $candidate) {
            if (str_contains($lower, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function sentenceWithRecipe(string $prefix, ?string $audience, ?string $recipe): string
    {
        $sentence = $prefix;
        if ($audience) {
            $sentence .= ' ' . $audience;
        }
        if ($recipe) {
            $sentence .= self::recipePrefix($recipe) . $recipe;
        }
        return rtrim($sentence, '. ') . '.';
    }

    private static function recipePrefix(string $recipe): string
    {
        return match (true) {
            str_starts_with($recipe, 'sabor ') => ' ',
            str_starts_with($recipe, 'receta ') => ' con ',
            $recipe === 'mar' => ' con sabor a ',
            $recipe === 'delicias del mar' => ' con sabor a ',
            $recipe === 'festival marino' => ' con sabor a ',
            $recipe === 'sabores del mar' => ' con sabor a ',
            $recipe === 'aroma a catnip' => ' con ',
            default => ' con ',
        };
    }

    private static function buildPresentationText(array $attributes): ?string
    {
        $presentation = trim((string)($attributes['presentation'] ?? ''));
        if ($presentation !== '') {
            return 'Presentacion ' . $presentation . '.';
        }

        return self::buildSizeText($attributes);
    }

    private static function buildSizeText(array $attributes): ?string
    {
        $size = trim((string)($attributes['size'] ?? ''));
        if ($size === '') {
            return null;
        }

        if (preg_match('/^(XS|S|M|L|XL|STANDARD)$/i', $size)) {
            return 'Tamano ' . strtoupper($size) . '.';
        }

        return 'Presentacion ' . self::formatSizeValue($size) . '.';
    }

    private static function buildRangeText(array $attributes): ?string
    {
        $range = trim((string)($attributes['range'] ?? ''));
        if ($range === '') {
            return null;
        }
        return 'Rango de uso ' . $range . '.';
    }

    private static function buildSnackExtra(string $name, array $attributes): ?string
    {
        $lower = strtolower($name);
        if (str_contains($lower, 'dental')) {
            return 'Uso orientado a cuidado dental.';
        }

        $presentation = trim((string)($attributes['presentation'] ?? ''));
        return $presentation !== '' ? null : null;
    }

    private static function buildShampooSentence(string $name): string
    {
        if (stripos($name, 'antiparasitario') !== false) {
            return 'Shampoo antiparasitario para mascotas.';
        }
        if (stripos($name, 'hipoalergenico') !== false) {
            return 'Shampoo hipoalergenico para mascotas.';
        }
        return 'Shampoo de uso regular para mascotas.';
    }

    private static function buildGroomingSentence(string $name, string $gender): string
    {
        $species = match ($gender) {
            'cat' => 'gatos',
            'dog' => 'perros',
            default => 'perros y gatos',
        };
        return 'Herramienta de aseo y cuidado para ' . $species . '.';
    }

    private static function buildHygieneSentence(string $brand, string $name, string $gender): string
    {
        if ($brand === 'AMONEX') {
            return 'Neutralizador de olores para espacios de mascotas.';
        }
        if (stripos($name, 'clorhexidina') !== false) {
            return 'Producto de higiene para mascotas con clorhexidina.';
        }
        if (stripos($name, 'miconazol') !== false) {
            return 'Producto de higiene para mascotas con miconazol.';
        }
        if (stripos($name, 'fibropinil') !== false) {
            return 'Producto de higiene y cuidado para mascotas con Fibropinil.';
        }
        if (stripos($name, 'bano en seco') !== false) {
            return 'Producto de bano en seco para mascotas.';
        }
        if (stripos($name, 'colonia') !== false) {
            return 'Colonia para mascotas.';
        }
        return 'Producto de higiene y cuidado para ' . match ($gender) {
            'cat' => 'gatos',
            'dog' => 'perros',
            default => 'mascotas',
        } . '.';
    }

    private static function buildAntiparasiticSentence(string $brand, string $name, string $gender): string
    {
        if ($brand === 'FRONTLINE' && stripos($name, 'Spray') !== false) {
            return 'Spray antiparasitario con fipronil para perros y gatos.';
        }
        if ($brand === 'FRONTLINE' && stripos($name, 'Pipetas Gatos') !== false) {
            return 'Pipeta antiparasitaria para gatos.';
        }
        if ($brand === 'FRONTLINE' && stripos($name, 'Pipetas Perros') !== false) {
            return 'Pipeta antiparasitaria para perros.';
        }
        if ($brand === 'NEXGARD' && stripos($name, 'Spectra') !== false) {
            return 'Antiparasitario oral de amplio espectro para perros.';
        }
        if ($brand === 'NEXGARD' && stripos($name, 'Tripack') !== false) {
            return 'Antiparasitario oral para perros en presentacion tripack.';
        }
        if ($brand === 'NEXGARD' && stripos($name, 'Combo Gatos') !== false) {
            return 'Antiparasitario spot-on para gatos.';
        }
        if ($brand === 'NEXGARD') {
            return 'Antiparasitario oral para perros.';
        }
        if ($brand === 'BRAVECTO') {
            return $gender === 'cat' ? 'Antiparasitario felino.' : 'Antiparasitario canino.';
        }
        return 'Antiparasitario veterinario.';
    }

    private static function buildVaccineSentence(string $brand, string $name, string $gender): string
    {
        if ($brand === 'RECOMBITEK') {
            return 'Vacuna veterinaria Recombitek para inmunizacion canina.';
        }
        if ($brand === 'RABISIN') {
            return 'Vacuna antirrabica veterinaria.';
        }
        return $gender === 'cat' ? 'Vacuna veterinaria felina.' : 'Vacuna veterinaria canina.';
    }

    private static function buildTherapeuticSentence(string $brand, string $name): string
    {
        if ($brand === 'PREVICOX') {
            return 'Antiinflamatorio no esteroideo veterinario a base de firocoxib.';
        }
        if ($brand === 'VETMEDIN') {
            return 'Tratamiento cardiologico veterinario a base de pimobendan.';
        }
        return 'Producto terapeutico veterinario.';
    }

    private static function buildHomeCareSentence(string $brand, string $name): string
    {
        return match ($brand) {
            'KLERAT' => 'Producto para control de roedores e insectos en el hogar.',
            'ZAP' => 'Insecticida de uso domestico para control de plagas.',
            'DRAGON' => 'Insecticida liquido de uso domestico.',
            default => 'Producto de cuidado para el hogar.',
        };
    }

    private static function buildGenericSentence(string $brand, string $name, string $category, string $gender): string
    {
        $subject = match ($category) {
            'Alimento para perros' => 'Producto para perros',
            'Alimento para gatos' => 'Producto para gatos',
            'cuidado' => 'Producto de cuidado para mascotas',
            default => 'Producto para mascotas',
        };

        if ($brand !== '') {
            $subject .= ' de la marca ' . $brand;
        }

        return $subject . '.';
    }

    private static function cleanupName(string $name): string
    {
        $name = preg_replace('/\s+/', ' ', trim($name)) ?? trim($name);
        $name = str_replace(['  ', '”', '“'], [' ', '"', '"'], $name);
        $name = preg_replace('/\bMg\b/', 'mg', $name) ?? $name;
        $name = preg_replace('/\bMl\b/', 'ml', $name) ?? $name;
        $name = preg_replace('/\bX(\d+)\b/', 'x$1', $name) ?? $name;
        $name = preg_replace('/\bDha\b/', 'DHA', $name) ?? $name;
        $name = preg_replace('/\bBb\b/', 'BB', $name) ?? $name;
        $name = preg_replace('/\bKc\b/', 'KC', $name) ?? $name;
        $name = preg_replace('/\s+Sa$/', '', $name) ?? $name;
        return $name;
    }

    private static function formatSizeValue(string $size): string
    {
        $value = strtoupper(trim($size));

        $replacements = [
            '/^(\d+(?:\.\d+)?)KG$/' => '$1 kg',
            '/^(\d+(?:\.\d+)?)GR?$/' => '$1 g',
            '/^(\d+(?:\.\d+)?)G$/' => '$1 g',
            '/^(\d+(?:\.\d+)?)LB$/' => '$1 lb',
            '/^(\d+(?:\.\d+)?)ML$/' => '$1 ml',
            '/^(\d+)GL$/' => '$1 galon',
            '/^(\d+)DS$/' => '$1 dosis',
            '/^(\d+)TAB$/' => '$1 tableta',
            '/^(\d+)X(\d+)DS$/' => '$1 x $2 dosis',
            '/^X(\d+)$/' => '$1 unidades',
        ];

        foreach ($replacements as $pattern => $replacement) {
            if (preg_match($pattern, $value)) {
                return preg_replace($pattern, $replacement, $value) ?? $size;
            }
        }

        return $size;
    }

    private static function joinSentences(array $sentences): string
    {
        $filtered = array_values(array_filter(array_map(static function ($sentence) {
            $value = trim((string)$sentence);
            if ($value === '') {
                return null;
            }
            return rtrim($value, '. ') . '.';
        }, $sentences)));

        return implode(' ', $filtered);
    }

    private static function normalizeAttributes(mixed $attributes): array
    {
        if (is_array($attributes)) {
            return $attributes;
        }

        if (is_string($attributes) && $attributes !== '') {
            $decoded = json_decode($attributes, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
