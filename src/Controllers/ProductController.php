<?php

namespace App\Controllers;

use App\Repositories\ProductRepository;
use App\Core\Response;

class ProductController {
    private $productRepository;

    public function __construct() {
        $this->productRepository = new ProductRepository();
    }

    public function index() {
        try {
            $products = $this->productRepository->getAll();
            Response::json($products);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'PRODUCTS_LIST_FAILED');
        }
    }

    public function show($id) {
        try {
            $product = $this->productRepository->getById($id);
            if (!$product) {
                Response::error('Producto no encontrado', 404, 'PRODUCT_NOT_FOUND');
                return;
            }
            Response::json($product);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'PRODUCT_FETCH_FAILED');
        }
    }

    public function store() {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $productType = strtolower($data['productType'] ?? $data['product_type'] ?? '');
            $attributes = $data['attributes'] ?? [];
            $allowedTypes = ['comida', 'ropa', 'accesorios'];
            if (!$productType) {
                Response::error('Tipo de producto requerido', 400, 'PRODUCT_TYPE_REQUIRED');
                return;
            }
            if (!in_array($productType, $allowedTypes, true)) {
                Response::error('Tipo de producto inválido', 400, 'PRODUCT_TYPE_INVALID');
                return;
            }
            $requiredFields = ['name', 'category', 'price', 'quantity', 'brand'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || (is_string($data[$field]) && trim((string)$data[$field]) === '')) {
                    Response::error('Campos obligatorios incompletos', 400, 'PRODUCT_FIELDS_REQUIRED', ['field' => $field]);
                    return;
                }
            }
            if (!is_numeric($data['price']) || floatval($data['price']) < 0) {
                Response::error('Precio inválido', 400, 'PRODUCT_PRICE_INVALID');
                return;
            }
            if (!is_numeric($data['quantity']) || intval($data['quantity']) < 0) {
                Response::error('Cantidad inválida', 400, 'PRODUCT_QUANTITY_INVALID');
                return;
            }
            if (isset($data['cost']) && (!is_numeric($data['cost']) || floatval($data['cost']) < 0)) {
                Response::error('Costo inválido', 400, 'PRODUCT_COST_INVALID');
                return;
            }
            if (!isset($data['description']) || trim((string)$data['description']) === '') {
                Response::error('Descripción requerida', 400, 'PRODUCT_DESCRIPTION_REQUIRED');
                return;
            }
            $required = ['sku', 'tag', 'species'];
            foreach ($required as $key) {
                if (!isset($attributes[$key]) || trim((string)$attributes[$key]) === '') {
                    Response::error('Atributos obligatorios incompletos', 400, 'PRODUCT_ATTRIBUTES_REQUIRED', ['field' => $key]);
                    return;
                }
            }
            $product = $this->productRepository->create($data);
            Response::json($product, 201);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 400, 'PRODUCT_CREATE_FAILED');
        }
    }

    public function update($id) {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $productType = isset($data['productType']) ? strtolower((string)$data['productType']) : (isset($data['product_type']) ? strtolower((string)$data['product_type']) : null);
            if ($productType !== null) {
                $allowedTypes = ['comida', 'ropa', 'accesorios'];
                if ($productType === '') {
                    Response::error('Tipo de producto requerido', 400, 'PRODUCT_TYPE_REQUIRED');
                    return;
                }
                if (!in_array($productType, $allowedTypes, true)) {
                    Response::error('Tipo de producto inválido', 400, 'PRODUCT_TYPE_INVALID');
                    return;
                }
            }
            $numericRules = [
                'price' => 'PRODUCT_PRICE_INVALID',
                'quantity' => 'PRODUCT_QUANTITY_INVALID',
                'cost' => 'PRODUCT_COST_INVALID'
            ];
            foreach ($numericRules as $field => $errorCode) {
                if (isset($data[$field])) {
                    if (!is_numeric($data[$field]) || floatval($data[$field]) < 0) {
                        Response::error('Valor inválido', 400, $errorCode, ['field' => $field]);
                        return;
                    }
                }
            }
            $requiredFields = ['name', 'category', 'brand', 'description'];
            foreach ($requiredFields as $field) {
                if (array_key_exists($field, $data) && is_string($data[$field]) && trim((string)$data[$field]) === '') {
                    Response::error('Campos obligatorios incompletos', 400, 'PRODUCT_FIELDS_REQUIRED', ['field' => $field]);
                    return;
                }
            }
            if (isset($data['productType']) || isset($data['product_type']) || isset($data['attributes'])) {
                $attributes = $data['attributes'] ?? [];
                $required = ['sku', 'tag', 'species'];
                foreach ($required as $key) {
                    if (!isset($attributes[$key]) || trim((string)$attributes[$key]) === '') {
                        Response::error('Atributos obligatorios incompletos', 400, 'PRODUCT_ATTRIBUTES_REQUIRED', ['field' => $key]);
                        return;
                    }
                }
            }
            $product = $this->productRepository->update($id, $data);
            if (!$product) {
                Response::error('Producto no encontrado', 404, 'PRODUCT_NOT_FOUND');
                return;
            }
            Response::json($product);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 400, 'PRODUCT_UPDATE_FAILED');
        }
    }

    public function destroy($id) {
        try {
            $this->productRepository->delete($id);
            Response::json(['deleted' => true], 200, null, 'Producto eliminado');
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'PRODUCT_DELETE_FAILED');
        }
    }
}
