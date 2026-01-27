<?php

namespace App\Repositories;

use App\Core\Database;

class ProductRepository {
    private $db;
    private $taxRateCache = null;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    private function getBaseQuery() {
        return '
        SELECT
          p.id,
          p.legacy_id AS "legacyId",
          p.category AS "category",
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
          COALESCE(img.images, \'[]\') AS images,
          COALESCE(var.variations, \'[]\') AS variations
        FROM "Product" p
        LEFT JOIN LATERAL (
          SELECT json_agg(i.url ORDER BY i.id) AS images
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
        $sql = $this->getBaseQuery() . ' ORDER BY p.created_at DESC';
        $stmt = $this->db->query($sql);
        $rows = $stmt->fetchAll();
        return array_map([$this, 'formatRow'], $rows);
    }

    public function getById($idOrLegacyOrSlug) {
        $sql = $this->getBaseQuery() . ' WHERE p.id = :id OR p.legacy_id = :id OR p.slug = :id LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $idOrLegacyOrSlug]);
        $row = $stmt->fetch();
        return $row ? $this->formatRow($row) : null;
    }

    private function formatRow($row) {
        $row['images'] = json_decode($row['images'] ?? '[]', true);
        $row['variations'] = json_decode($row['variations'] ?? '[]', true);

        $taxRate = $this->getTaxRate();
        $taxMultiplier = 1 + ($taxRate / 100);
        $row['price'] = round(floatval($row['price'] ?? 0) * $taxMultiplier, 2);
        $row['originPrice'] = round(floatval($row['originPrice'] ?? 0) * $taxMultiplier, 2);
        
        // Smart Business Logic
        $cost = floatval($row['cost'] ?? 0);
        $price = floatval($row['price'] ?? 0);
        $priceNet = $taxMultiplier > 0 ? ($price / $taxMultiplier) : $price;
        
        if ($cost > 0) {
            $row['business'] = [
                'cost' => $cost,
                'margin' => $priceNet > 0 ? round((($priceNet - $cost) / $priceNet) * 100, 1) : 0,
                'profit' => round($priceNet - $cost, 2),
                'suggestions' => [
                    'min_price' => round($cost * 1.15, 2),
                    'recommended_price' => round($cost * 1.35, 2),
                    'max_price' => round($cost * 1.60, 2)
                ]
            ];
        } else {
            // Default if no cost set yet
             $row['business'] = [
                'cost' => 0,
                'margin' => 100,
                'profit' => $priceNet,
                'suggestions' => [
                    'min_price' => round($priceNet * 0.8, 2),
                    'recommended_price' => round($priceNet, 2),
                    'max_price' => round($priceNet * 1.2, 2)
                ]
            ];
        }

        return $row;
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

    public function create($data) {
        $sql = '
            INSERT INTO "Product" (
                id, legacy_id, category, name, gender, is_new, is_sale, price, original_price, cost, brand, sold, quantity, description, action, slug, created_at, updated_at
            ) VALUES (
                :id, :legacy_id, :category, :name, :gender, :is_new, :is_sale, :price, :original_price, :cost, :brand, :sold, :quantity, :description, :action, :slug, NOW(), NOW()
            ) RETURNING id
        ';
        
        $params = [
            'id' => uniqid('prod_'),
            'legacy_id' => $data['legacyId'] ?? uniqid(),
            'category' => $data['category'] ?? 'General',
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
            'slug' => $data['slug'] ?? strtolower(str_replace(' ', '-', $data['name'])) . '-' . uniqid()
        ];
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $this->getById($result['id']);
    }

    public function update($id, $data) {
        // Build dynamic update query
        $fields = [];
        $params = ['id' => $id];
        
        $mapping = [
            'category' => 'category',
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
            'slug' => 'slug'
        ];

        foreach ($data as $key => $value) {
            if (isset($mapping[$key])) {
                $dbField = $mapping[$key];
                $fields[] = "\"$dbField\" = :$key";
                $params[$key] = $value;
            }
        }

        if (empty($fields)) {
            return $this->getById($id);
        }

        $fields[] = '"updated_at" = NOW()';
        $sql = 'UPDATE "Product" SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $this->getById($id);
    }

    public function delete($id) {
        // Delete related images and variations first (if cascading isn't set up, but let's assume simple delete for now)
         $stmt = $this->db->prepare('DELETE FROM "Product" WHERE id = :id');
         $stmt->execute(['id' => $id]);
    }}
