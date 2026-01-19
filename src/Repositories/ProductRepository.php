<?php

namespace App\Repositories;

use App\Core\Database;

class ProductRepository {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    private function getBaseQuery() {
        return '
        SELECT
          p.id,
          p."legacyId",
          p.categoria AS "category",
          p.nombre AS "name",
          p.genero AS "gender",
          p.nuevo AS "new",
          p.oferta AS "sale",
          p.precio AS "price",
          p.precio_original AS "originPrice",
          p."cost" AS "cost", 
          p.marca AS "brand",
          p.vendido AS "sold",
          p.cantidad AS "quantity",
          p.descripcion AS "description",
          p.accion AS "action",
          p.slug AS "slug",
          COALESCE(img.images, \'[]\') AS images,
          COALESCE(var.variations, \'[]\') AS variations
        FROM "Product" p
        LEFT JOIN LATERAL (
          SELECT json_agg(i.url ORDER BY i.id) AS images
          FROM "Image" i
          WHERE i."productId" = p.id
        ) img ON true
        LEFT JOIN LATERAL (
          SELECT json_agg(jsonb_build_object(
            \'color\', v.color,
            \'colorCode\', v."colorCode",
            \'colorImage\', v."colorImage",
            \'image\', v.image
          ) ORDER BY v.id) AS variations
          FROM "Variation" v
          WHERE v."productId" = p.id
        ) var ON true
        ';
    }

    public function getAll() {
        $sql = $this->getBaseQuery() . ' ORDER BY p."fecha_de_creacion" DESC';
        $stmt = $this->db->query($sql);
        $rows = $stmt->fetchAll();
        return array_map([$this, 'formatRow'], $rows);
    }

    public function getById($idOrLegacyOrSlug) {
        $sql = $this->getBaseQuery() . ' WHERE p.id = :id OR p."legacyId" = :id OR p.slug = :id LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $idOrLegacyOrSlug]);
        $row = $stmt->fetch();
        return $row ? $this->formatRow($row) : null;
    }

    private function formatRow($row) {
        $row['images'] = json_decode($row['images'] ?? '[]', true);
        $row['variations'] = json_decode($row['variations'] ?? '[]', true);
        
        // Smart Business Logic
        $cost = floatval($row['cost'] ?? 0);
        $price = floatval($row['price'] ?? 0);
        
        if ($cost > 0) {
            $row['business'] = [
                'cost' => $cost,
                'margin' => $price > 0 ? round((($price - $cost) / $price) * 100, 1) : 0,
                'profit' => round($price - $cost, 2),
                'suggestions' => [
                    'min_price' => round($cost * 1.15, 2),        // 15% margin
                    'recommended_price' => round($cost * 1.35, 2), // 35% margin
                    'max_price' => round($cost * 1.60, 2)         // 60% margin
                ]
            ];
        } else {
            // Default if no cost set yet
             $row['business'] = [
                'cost' => 0,
                'margin' => 100,
                'profit' => $price,
                'suggestions' => [
                    'min_price' => round($price * 0.8, 2),
                    'recommended_price' => $price,
                    'max_price' => round($price * 1.2, 2)
                ]
            ];
        }

        return $row;
    }

    public function create($data) {
        $sql = '
            INSERT INTO "Product" (
                "legacyId", categoria, nombre, genero, nuevo, oferta, precio, precio_original, cost, marca, vendido, cantidad, descripcion, accion, slug, "fecha_de_creacion"
            ) VALUES (
                :legacyId, :category, :name, :gender, :new, :sale, :price, :originPrice, :cost, :brand, :sold, :quantity, :description, :action, :slug, NOW()
            ) RETURNING id
        ';
        
        $params = [
            'legacyId' => $data['legacyId'] ?? uniqid(),
            'category' => $data['category'] ?? 'General',
            'name' => $data['name'],
            'gender' => $data['gender'] ?? 'Unisex',
            'new' => isset($data['new']) ? ($data['new'] ? 'true' : 'false') : 'true',
            'sale' => isset($data['sale']) ? ($data['sale'] ? 'true' : 'false') : 'false',
            'price' => $data['price'],
            'originPrice' => $data['originPrice'] ?? $data['price'],
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
            'category' => 'categoria',
            'name' => 'nombre',
            'gender' => 'genero',
            'new' => 'nuevo',
            'sale' => 'oferta',
            'price' => 'precio',
            'originPrice' => 'precio_original',
            'cost' => 'cost',
            'brand' => 'marca',
            'sold' => 'vendido',
            'quantity' => 'cantidad',
            'description' => 'descripcion',
            'action' => 'accion',
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
