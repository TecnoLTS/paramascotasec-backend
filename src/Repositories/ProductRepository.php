<?php

namespace App\Repositories;

use App\Core\Database;
use App\Core\TenantContext;

class ProductRepository {
    private $db;
    private $taxRateCache = null;
    private $pricingSettingsCache = null;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->ensureTenantColumn();
        $this->ensureAttributesColumns();
        $this->ensureImageColumns();
    }

    private function getBaseQuery() {
        return '
        SELECT
          p.id,
          p.legacy_id AS "legacyId",
          p.category AS "category",
          p.product_type AS "productType",
          p.name AS "name",
          p.gender AS "gender",
          p.is_new AS "new",
          p.is_sale AS "sale",
          p.price AS "price",
          p.original_price AS "originPrice",
          p.cost AS "cost", 
          p.brand AS "brand",
          p.sold AS "sold",
          p.quantity AS "quantity",
          p.description AS "description",
          p.action AS "action",
          p.slug AS "slug",
          COALESCE(p.attributes, \'{}\') AS attributes,
          COALESCE(img.images, \'[]\') AS images,
          COALESCE(img.thumbs, \'[]\') AS thumbs,
          COALESCE(img.image_meta, \'[]\') AS "imageMeta",
          COALESCE(var.variations, \'[]\') AS variations
        FROM "Product" p
        LEFT JOIN LATERAL (
          SELECT
            json_agg(i.url ORDER BY i.id) FILTER (WHERE COALESCE(i.kind, \'gallery\') = \'gallery\') AS images,
            json_agg(i.url ORDER BY i.id) FILTER (WHERE COALESCE(i.kind, \'gallery\') = \'thumb\') AS thumbs,
            json_agg(jsonb_build_object(
              \'url\', i.url,
              \'width\', i.width,
              \'height\', i.height,
              \'kind\', COALESCE(i.kind, \'gallery\')
            ) ORDER BY i.id) AS image_meta
          FROM "Image" i
          WHERE i.product_id = p.id
        ) img ON true
        LEFT JOIN LATERAL (
          SELECT json_agg(jsonb_build_object(
            \'color\', v.color,
            \'colorCode\', v.color_code,
            \'colorImage\', v.color_image,
            \'image\', v.image
          ) ORDER BY v.id) AS variations
          FROM "Variation" v
          WHERE v.product_id = p.id
        ) var ON true
        ';
    }

    public function getAll() {
        $sql = $this->getBaseQuery() . ' WHERE p.tenant_id = :tenant_id ORDER BY p.created_at DESC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        $rows = $stmt->fetchAll();
        return array_map([$this, 'formatRow'], $rows);
    }

    public function getById($idOrLegacyOrSlug) {
        $sql = $this->getBaseQuery() . ' WHERE p.tenant_id = :tenant_id AND (p.id = :id OR p.legacy_id = :id OR p.slug = :id) LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $idOrLegacyOrSlug,
            'tenant_id' => $this->getTenantId()
        ]);
        $row = $stmt->fetch();
        return $row ? $this->formatRow($row) : null;
    }

    private function formatRow($row) {
        $row['images'] = json_decode($row['images'] ?? '[]', true);
        $row['thumbImage'] = json_decode($row['thumbs'] ?? '[]', true);
        $row['imageMeta'] = json_decode($row['imageMeta'] ?? '[]', true);
        $row['variations'] = json_decode($row['variations'] ?? '[]', true);
        $row['attributes'] = json_decode($row['attributes'] ?? '{}', true) ?: [];

        $row['images'] = array_map([$this, 'normalizeImageUrl'], $row['images']);
        $row['thumbImage'] = array_map([$this, 'normalizeImageUrl'], $row['thumbImage']);
        $row['imageMeta'] = array_map(function ($item) {
            if (is_array($item) && isset($item['url'])) {
                $item['url'] = $this->normalizeImageUrl($item['url']);
            }
            return $item;
        }, $row['imageMeta']);
        $row['variations'] = array_map(function ($variation) {
            if (isset($variation['image'])) {
                $variation['image'] = $this->normalizeImageUrl($variation['image']);
            }
            if (isset($variation['colorImage'])) {
                $variation['colorImage'] = $this->normalizeImageUrl($variation['colorImage']);
            }
            return $variation;
        }, $row['variations']);

        $taxRate = $this->getTaxRate();
        $taxMultiplier = 1 + ($taxRate / 100);
        $row['price'] = round(floatval($row['price'] ?? 0) * $taxMultiplier, 2);
        $row['originPrice'] = round(floatval($row['originPrice'] ?? 0) * $taxMultiplier, 2);
        
        // Smart Business Logic
        $cost = floatval($row['cost'] ?? 0);
        $price = floatval($row['price'] ?? 0);
        $priceNet = $taxMultiplier > 0 ? ($price / $taxMultiplier) : $price;
        $pricing = $this->getPricingSettings();

        if ($cost > 0) {
            $minMargin = $pricing['minMargin'];
            $baseMargin = $pricing['baseMargin'];
            $targetMargin = $pricing['targetMargin'];
            $promoBuffer = $pricing['promoBuffer'];
            $strategy = $pricing['strategy'];
            $rounding = $pricing['rounding'];
            $includeVatInPvp = $pricing['includeVatInPvp'];
            $shippingBuffer = $pricing['shippingBuffer'];

            $minPriceNet = $this->priceFromMargin($cost, $minMargin);
            $targetPriceNet = $this->priceFromMargin($cost, $targetMargin + $promoBuffer);
            $recommendedNet = $minPriceNet;

            if ($strategy === 'target_margin') {
                $recommendedNet = $this->priceFromMargin($cost, $targetMargin + $promoBuffer);
            } elseif ($strategy === 'competitive') {
                $recommendedNet = $this->priceFromMargin($cost, $minMargin);
            } else {
                $recommendedNet = $cost * (1 + (($baseMargin + $promoBuffer) / 100));
            }

            if ($recommendedNet < $minPriceNet) {
                $recommendedNet = $minPriceNet;
            }

            $minPriceNet = $this->applyPricingAdjustments($minPriceNet, $taxMultiplier, $rounding, $includeVatInPvp, $shippingBuffer);
            $recommendedNet = $this->applyPricingAdjustments($recommendedNet, $taxMultiplier, $rounding, $includeVatInPvp, $shippingBuffer);
            $maxPriceNet = $this->applyPricingAdjustments($targetPriceNet, $taxMultiplier, $rounding, $includeVatInPvp, $shippingBuffer);

            if ($recommendedNet < $minPriceNet) {
                $recommendedNet = $minPriceNet;
            }
            if ($maxPriceNet < $recommendedNet) {
                $maxPriceNet = $recommendedNet;
            }

            $row['business'] = [
                'cost' => $cost,
                'margin' => $priceNet > 0 ? round((($priceNet - $cost) / $priceNet) * 100, 1) : 0,
                'profit' => round($priceNet - $cost, 2),
                'suggestions' => [
                    'min_price' => round($minPriceNet, 2),
                    'recommended_price' => round($recommendedNet, 2),
                    'max_price' => round($maxPriceNet, 2),
                    'min_price_pvp' => round($minPriceNet * $taxMultiplier, 2),
                    'recommended_price_pvp' => round($recommendedNet * $taxMultiplier, 2),
                    'max_price_pvp' => round($maxPriceNet * $taxMultiplier, 2)
                ]
            ];
        } else {
            // Default if no cost set yet
            $roundedNet = $this->applyPricingAdjustments($priceNet, $taxMultiplier, $pricing['rounding'], $pricing['includeVatInPvp'], $pricing['shippingBuffer']);
            $row['business'] = [
                'cost' => 0,
                'margin' => 100,
                'profit' => $priceNet,
                'suggestions' => [
                    'min_price' => round($roundedNet * 0.8, 2),
                    'recommended_price' => round($roundedNet, 2),
                    'max_price' => round($roundedNet * 1.2, 2),
                    'min_price_pvp' => round(($roundedNet * 0.8) * $taxMultiplier, 2),
                    'recommended_price_pvp' => round($roundedNet * $taxMultiplier, 2),
                    'max_price_pvp' => round(($roundedNet * 1.2) * $taxMultiplier, 2)
                ]
            ];
        }

        return $row;
    }

    private function getPublicBaseUrl() {
        $base = TenantContext::publicBaseUrl()
            ?? ($_ENV['APP_URL'] ?? ($_ENV['BACKEND_PUBLIC_URL'] ?? 'http://localhost:8080'));
        return rtrim($base, '/');
    }

    private function normalizeImageUrl($url) {
        if (!$url || !is_string($url)) {
            return $url;
        }
        if (strpos($url, '/uploads/') === 0) {
            return $url;
        }
        if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
            return $url;
        }
        return $this->getPublicBaseUrl() . '/' . ltrim($url, '/');
    }

    private function ensureAttributesColumns() {
        $checkType = $this->db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'Product' AND column_name = 'product_type'");
        $checkType->execute();
        if (!$checkType->fetch()) {
            $this->db->exec('ALTER TABLE "Product" ADD COLUMN IF NOT EXISTS product_type text');
        }

        $checkAttrs = $this->db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'Product' AND column_name = 'attributes'");
        $checkAttrs->execute();
        if (!$checkAttrs->fetch()) {
            $this->db->exec('ALTER TABLE "Product" ADD COLUMN IF NOT EXISTS attributes jsonb');
        }
    }

    private function ensureTenantColumn() {
        $check = $this->db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'Product' AND column_name = 'tenant_id'");
        $check->execute();
        if ($check->fetch()) {
            return;
        }
        $this->db->exec('ALTER TABLE "Product" ADD COLUMN IF NOT EXISTS tenant_id text');
        $this->db->exec('UPDATE "Product" SET tenant_id = COALESCE(tenant_id, \'' . $this->getTenantId() . '\')');
    }

    private function getTenantId() {
        return TenantContext::id() ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec');
    }

    private function ensureImageColumns() {
        $checkKind = $this->db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'Image' AND column_name = 'kind'");
        $checkKind->execute();
        if (!$checkKind->fetch()) {
            $this->db->exec('ALTER TABLE "Image" ADD COLUMN IF NOT EXISTS kind text');
        }
        $checkWidth = $this->db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'Image' AND column_name = 'width'");
        $checkWidth->execute();
        if (!$checkWidth->fetch()) {
            $this->db->exec('ALTER TABLE "Image" ADD COLUMN IF NOT EXISTS width integer');
        }
        $checkHeight = $this->db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'Image' AND column_name = 'height'");
        $checkHeight->execute();
        if (!$checkHeight->fetch()) {
            $this->db->exec('ALTER TABLE "Image" ADD COLUMN IF NOT EXISTS height integer');
        }
    }

    private function normalizeImageEntries($items, $defaultKind) {
        $entries = [];
        if (!is_array($items)) {
            return $entries;
        }
        foreach ($items as $item) {
            if (is_string($item)) {
                $url = trim($item);
                if ($url === '') {
                    continue;
                }
                $entries[] = [
                    'url' => $url,
                    'width' => null,
                    'height' => null,
                    'kind' => $defaultKind
                ];
                continue;
            }
            if (is_array($item)) {
                $url = trim($item['url'] ?? '');
                if ($url === '') {
                    continue;
                }
                $kind = $item['kind'] ?? $defaultKind;
                $width = isset($item['width']) && is_numeric($item['width']) ? intval($item['width']) : null;
                $height = isset($item['height']) && is_numeric($item['height']) ? intval($item['height']) : null;
                $entries[] = [
                    'url' => $url,
                    'width' => $width,
                    'height' => $height,
                    'kind' => $kind
                ];
            }
        }
        return $entries;
    }

    private function syncImages($productId, $entries, $kind) {
        if (!is_array($entries)) {
            return;
        }
        if ($kind === 'gallery') {
            $stmt = $this->db->prepare('DELETE FROM "Image" WHERE product_id = :id AND (kind = :kind OR kind IS NULL)');
            $stmt->execute(['id' => $productId, 'kind' => $kind]);
        } else {
            $stmt = $this->db->prepare('DELETE FROM "Image" WHERE product_id = :id AND kind = :kind');
            $stmt->execute(['id' => $productId, 'kind' => $kind]);
        }
        if (count($entries) === 0) {
            return;
        }
        $stmt = $this->db->prepare('INSERT INTO "Image" (id, url, product_id, kind, width, height) VALUES (:id, :url, :product_id, :kind, :width, :height)');
        foreach ($entries as $entry) {
            $stmt->execute([
                'id' => uniqid('img_'),
                'url' => $entry['url'],
                'product_id' => $productId,
                'kind' => $entry['kind'] ?? $kind,
                'width' => $entry['width'],
                'height' => $entry['height']
            ]);
        }
    }

    private function getTaxRate() {
        if ($this->taxRateCache !== null) {
            return $this->taxRateCache;
        }
        $settings = new \App\Repositories\SettingsRepository();
        $value = $settings->get('vat_rate');
        $rate = is_numeric($value) ? floatval($value) : 0;
        $this->taxRateCache = $rate;
        return $rate;
    }

    private function getPricingSettings() {
        if ($this->pricingSettingsCache !== null) {
            return $this->pricingSettingsCache;
        }
        $settings = new \App\Repositories\SettingsRepository();
        $baseMargin = $this->parseNumericSetting($settings->get('pricing_margin_base'), 30);
        $minMargin = $this->parseNumericSetting($settings->get('pricing_margin_min'), 15);
        $targetMargin = $this->parseNumericSetting($settings->get('pricing_margin_target'), 35);
        $promoBuffer = $this->parseNumericSetting($settings->get('pricing_margin_promo_buffer'), 5);
        $rounding = $this->parseNumericSetting($settings->get('pricing_calc_rounding'), 0.05);
        $shippingBuffer = $this->parseNumericSetting($settings->get('pricing_calc_shipping_buffer'), 0);
        $strategy = $settings->get('pricing_calc_strategy') ?? 'cost_plus';
        $includeVatInPvp = $this->parseBoolSetting($settings->get('pricing_calc_include_vat'), true);

        $minMargin = max(0, $minMargin);
        $baseMargin = max($minMargin, $baseMargin);
        $targetMargin = max($baseMargin, $targetMargin);
        $promoBuffer = max(0, $promoBuffer);

        $allowed = ['cost_plus', 'target_margin', 'competitive'];
        if (!in_array($strategy, $allowed, true)) {
            $strategy = 'cost_plus';
        }

        $this->pricingSettingsCache = [
            'baseMargin' => $baseMargin,
            'minMargin' => $minMargin,
            'targetMargin' => $targetMargin,
            'promoBuffer' => $promoBuffer,
            'rounding' => max(0, $rounding),
            'strategy' => $strategy,
            'includeVatInPvp' => $includeVatInPvp,
            'shippingBuffer' => max(0, $shippingBuffer)
        ];

        return $this->pricingSettingsCache;
    }

    private function parseNumericSetting($value, $default) {
        return is_numeric($value) ? floatval($value) : $default;
    }

    private function parseBoolSetting($value, $default) {
        if ($value === null) return $default;
        if (is_bool($value)) return $value;
        $normalized = strtolower(trim((string)$value));
        if (in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true)) return true;
        if (in_array($normalized, ['0', 'false', 'no', 'n', 'off'], true)) return false;
        return $default;
    }

    private function priceFromMargin($cost, $marginPct) {
        $margin = max(0, min(95, $marginPct));
        $denom = 1 - ($margin / 100);
        if ($denom <= 0) return $cost;
        return $cost / $denom;
    }

    private function roundToIncrement($value, $increment) {
        if ($increment <= 0) return $value;
        return round($value / $increment) * $increment;
    }

    private function applyPricingAdjustments($priceNet, $taxMultiplier, $rounding, $includeVatInPvp, $shippingBuffer) {
        $adjusted = $priceNet * (1 + (max(0, $shippingBuffer) / 100));
        if ($rounding > 0) {
            if ($includeVatInPvp && $taxMultiplier > 0) {
                $pvp = $this->roundToIncrement($adjusted * $taxMultiplier, $rounding);
                $adjusted = $taxMultiplier > 0 ? ($pvp / $taxMultiplier) : $pvp;
            } else {
                $adjusted = $this->roundToIncrement($adjusted, $rounding);
            }
        }
        return max(0, $adjusted);
    }

    public function create($data) {
        $sql = '
            INSERT INTO "Product" (
                id, legacy_id, tenant_id, category, product_type, name, gender, is_new, is_sale, price, original_price, cost, brand, sold, quantity, description, action, slug, attributes, created_at, updated_at
            ) VALUES (
                :id, :legacy_id, :tenant_id, :category, :product_type, :name, :gender, :is_new, :is_sale, :price, :original_price, :cost, :brand, :sold, :quantity, :description, :action, :slug, :attributes, NOW(), NOW()
            ) RETURNING id
        ';
        
        $params = [
            'id' => uniqid('prod_'),
            'legacy_id' => $data['legacyId'] ?? uniqid(),
            'tenant_id' => $this->getTenantId(),
            'category' => $data['category'] ?? 'General',
            'product_type' => $data['productType'] ?? $data['product_type'] ?? null,
            'name' => $data['name'],
            'gender' => $data['gender'] ?? 'Unisex',
            'is_new' => isset($data['new']) ? ($data['new'] ? 'true' : 'false') : 'true',
            'is_sale' => isset($data['sale']) ? ($data['sale'] ? 'true' : 'false') : 'false',
            'price' => $data['price'],
            'original_price' => $data['originPrice'] ?? $data['price'],
            'cost' => $data['cost'] ?? 0,
            'brand' => $data['brand'] ?? 'Generico',
            'sold' => $data['sold'] ?? 0,
            'quantity' => $data['quantity'] ?? 0,
            'description' => $data['description'] ?? '',
            'action' => $data['action'] ?? 'view',
            'slug' => $data['slug'] ?? strtolower(str_replace(' ', '-', $data['name'])) . '-' . uniqid(),
            'attributes' => isset($data['attributes']) ? json_encode($data['attributes']) : null
        ];
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        $productId = $result['id'];

        $galleryInput = $data['images'] ?? $data['galleryImages'] ?? ($data['image'] ?? []);
        if (is_string($galleryInput)) {
            $galleryInput = [$galleryInput];
        }
        $thumbInput = $data['thumbImages'] ?? $data['thumbImage'] ?? [];
        $galleryEntries = $this->normalizeImageEntries($galleryInput, 'gallery');
        $thumbEntries = $this->normalizeImageEntries($thumbInput, 'thumb');
        if (count($galleryEntries) > 0) {
            $this->syncImages($productId, $galleryEntries, 'gallery');
        }
        if (count($thumbEntries) > 0) {
            $this->syncImages($productId, $thumbEntries, 'thumb');
        }
        return $this->getById($productId);
    }

    public function update($id, $data) {
        // Build dynamic update query
        $fields = [];
        $params = ['id' => $id];
        
        $mapping = [
            'category' => 'category',
            'productType' => 'product_type',
            'name' => 'name',
            'gender' => 'gender',
            'new' => 'is_new',
            'sale' => 'is_sale',
            'price' => 'price',
            'originPrice' => 'original_price',
            'cost' => 'cost',
            'brand' => 'brand',
            'sold' => 'sold',
            'quantity' => 'quantity',
            'description' => 'description',
            'action' => 'action',
            'slug' => 'slug',
            'attributes' => 'attributes'
        ];

        foreach ($data as $key => $value) {
            if (isset($mapping[$key])) {
                $dbField = $mapping[$key];
                $fields[] = "\"$dbField\" = :$key";
                if ($key === 'attributes') {
                    $params[$key] = is_string($value) ? $value : json_encode($value);
                } else {
                    $params[$key] = $value;
                }
            }
        }

        if (empty($fields)) {
            return $this->getById($id);
        }

        $fields[] = '"updated_at" = NOW()';
        $sql = 'UPDATE "Product" SET ' . implode(', ', $fields) . ' WHERE id = :id AND tenant_id = :tenant_id';
        $stmt = $this->db->prepare($sql);
        $params['tenant_id'] = $this->getTenantId();
        $stmt->execute($params);

        if (array_key_exists('images', $data) || array_key_exists('galleryImages', $data) || array_key_exists('image', $data)) {
            $galleryInput = $data['images'] ?? $data['galleryImages'] ?? ($data['image'] ?? []);
            if (is_string($galleryInput)) {
                $galleryInput = [$galleryInput];
            }
            $galleryEntries = $this->normalizeImageEntries($galleryInput, 'gallery');
            $this->syncImages($id, $galleryEntries, 'gallery');
        }
        if (array_key_exists('thumbImages', $data) || array_key_exists('thumbImage', $data)) {
            $thumbInput = $data['thumbImages'] ?? $data['thumbImage'] ?? [];
            $thumbEntries = $this->normalizeImageEntries($thumbInput, 'thumb');
            $this->syncImages($id, $thumbEntries, 'thumb');
        }
        
        return $this->getById($id);
    }

    public function delete($id) {
        // Delete related images and variations first (if cascading isn't set up, but let's assume simple delete for now)
         $stmt = $this->db->prepare('DELETE FROM "Product" WHERE id = :id AND tenant_id = :tenant_id');
         $stmt->execute(['id' => $id, 'tenant_id' => $this->getTenantId()]);
    }}
